<?php

namespace cadenzajon\stripecart\services;

use cadenzajon\stripecart\events\CheckoutEvent;
use cadenzajon\stripecart\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use craft\stripe\elements\Price;
use craft\stripe\events\CheckoutSessionEvent;
use craft\stripe\Plugin as StripePlugin;
use craft\stripe\services\Checkout as StripeCheckout;
use yii\base\Component;

/**
 * Converts the session cart into a Stripe Checkout Session via the official
 * plugin's checkout service.
 */
class Checkout extends Component
{
    /**
     * Fires before the cart is handed to the official plugin's checkout,
     * exposing line items and session params for modification.
     */
    public const EVENT_BEFORE_CHECKOUT = 'beforeCheckout';

    /**
     * Craft session key holding this checkout's one-time reference. It is sent
     * as the Stripe client_reference_id and matched on the success return, so
     * only the browser that started the checkout can clear its own cart.
     */
    public const SESSION_REF_KEY = 'stripe-cart:checkout-ref';

    /**
     * Fires when a checkout.session.completed webhook arrives, with the
     * Checkout Session payload.
     */
    public const EVENT_ORDER_COMPLETED = 'orderCompleted';

    /**
     * Returns the Stripe-hosted Checkout URL for the current cart.
     */
    public function getCheckoutUrl(): string
    {
        $lineItems = Plugin::getInstance()->cart->getLineItems();
        if (!$lineItems) {
            throw new \RuntimeException('The cart is empty.');
        }

        $settings = Plugin::getInstance()->getSettings()->checkout;

        $params = [];
        if (!empty($settings['shippingCountries'])) {
            $params['shipping_address_collection'] = ['allowed_countries' => $settings['shippingCountries']];
        }
        if (!empty($settings['allowPromotionCodes'])) {
            $params['allow_promotion_codes'] = true;
        }

        // Shipping options accept either pre-created Stripe shipping rate IDs
        // (shippingOptions) or inline shipping_rate_data definitions
        // (shippingRates). Both are applied at the Checkout Session level.
        $shippingOptions = [];
        foreach (($settings['shippingOptions'] ?? []) as $rateId) {
            $shippingOptions[] = ['shipping_rate' => $rateId];
        }
        foreach (($settings['shippingRates'] ?? []) as $rateData) {
            $shippingOptions[] = ['shipping_rate_data' => $rateData];
        }
        if ($shippingOptions) {
            $params['shipping_options'] = $shippingOptions;
        }

        // Stripe Tax. Prices without a tax_behavior fall back to the account's
        // default tax behavior (Tax settings), so no per-price change is needed.
        if (!empty($settings['automaticTax'])) {
            $params['automatic_tax'] = ['enabled' => true];
        }

        // One-time reference binding this checkout to this browser session, so
        // only the initiating visitor can clear their cart on the success return.
        $ref = Craft::$app->getSecurity()->generateRandomString(32);
        Craft::$app->getSession()->set(self::SESSION_REF_KEY, $ref);
        $params['client_reference_id'] = $ref;

        $defaultSuccessUrl = UrlHelper::actionUrl('stripe-cart/checkout/success');
        $defaultSuccessUrl .= (str_contains($defaultSuccessUrl, '?') ? '&' : '?')
            . 'session_id={CHECKOUT_SESSION_ID}';

        $event = new CheckoutEvent([
            'lineItems' => $lineItems,
            'params' => $params,
            'successUrl' => $this->siteUrl($settings['successUrl'] ?? $defaultSuccessUrl),
            'cancelUrl' => $this->siteUrl($settings['cancelUrl'] ?? 'cart'),
        ]);
        $this->trigger(self::EVENT_BEFORE_CHECKOUT, $event);

        // The official plugin's helper infers the checkout mode by looking up
        // every line's price ID, so it cannot handle inline price_data lines
        // (used for sale amounts). Create those sessions directly instead.
        if ($this->hasInlinePrices($event->lineItems)) {
            return $this->createSession($event);
        }

        return StripePlugin::getInstance()->getCheckout()->getCheckoutUrl(
            $event->lineItems,
            null,
            $event->successUrl,
            $event->cancelUrl,
            $event->params ?: null,
        );
    }

    /** @param array<array<string, mixed>> $lineItems */
    private function hasInlinePrices(array $lineItems): bool
    {
        foreach ($lineItems as $item) {
            if (isset($item['price_data'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates the Checkout Session directly, mirroring what the official
     * plugin's helper does (customer resolution, mode, its event) for carts it
     * cannot handle because they carry inline price_data.
     */
    private function createSession(CheckoutEvent $event): string
    {
        $data = [
            'line_items' => array_values($event->lineItems),
            'success_url' => $event->successUrl,
            'cancel_url' => $event->cancelUrl,
            'mode' => $this->modeFor($event->lineItems),
        ] + ($event->params ?: []);

        // Link an existing Stripe customer when we know the shopper, exactly as
        // the official helper does, and fall back to prefilling their email.
        $user = Craft::$app->getUser()->getIdentity();
        if ($user && !isset($data['customer']) && !isset($data['customer_email'])) {
            $customers = StripePlugin::getInstance()->getCustomers()->getCustomersByEmail($user->email);
            $customer = !empty($customers) ? reset($customers) : null;
            if ($customer) {
                $data['customer'] = $customer->stripeId;
            } else {
                $data['customer_email'] = $user->email;
            }
        }

        // Keep the official extension point working for this path too.
        $stripeCheckout = StripePlugin::getInstance()->getCheckout();
        $sessionEvent = new CheckoutSessionEvent(['params' => $data]);
        $stripeCheckout->trigger(StripeCheckout::EVENT_BEFORE_START_CHECKOUT_SESSION, $sessionEvent);

        // Re-derive the mode, since a handler may have changed the line items.
        $params = $sessionEvent->params;
        $params['mode'] = $this->modeFor($params['line_items'] ?? []);

        $session = StripePlugin::getInstance()->getApi()->getClient()
            ->checkout->sessions->create($params);

        return $session->url;
    }

    /**
     * 'subscription' when any line bills recurringly, otherwise 'payment'.
     * Inline price_data lines are always one-time (sales are limited to
     * one-time prices), so only referenced price IDs need looking up.
     *
     * @param array<array<string, mixed>> $lineItems
     */
    private function modeFor(array $lineItems): string
    {
        foreach ($lineItems as $item) {
            if (!isset($item['price'])) {
                continue;
            }
            $price = Price::find()->stripeId($item['price'])->status(null)->one();
            if ($price && ($price->getData()['type'] ?? null) === 'recurring') {
                return 'subscription';
            }
        }

        return 'payment';
    }

    /**
     * Builds an absolute site URL without URL-encoding a {CHECKOUT_SESSION_ID}
     * placeholder in the query string.
     */
    private function siteUrl(string $url): string
    {
        if (UrlHelper::isAbsoluteUrl($url)) {
            return $url;
        }

        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        $absolute = UrlHelper::siteUrl($path);

        if ($query === null) {
            return $absolute;
        }

        return $absolute . (str_contains($absolute, '?') ? '&' : '?') . $query;
    }
}
