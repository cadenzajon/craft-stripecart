<?php

namespace cadenzajon\stripecart\services;

use cadenzajon\stripecart\events\CheckoutEvent;
use cadenzajon\stripecart\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use craft\stripe\Plugin as StripePlugin;
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

        $event = new CheckoutEvent([
            'lineItems' => $lineItems,
            'params' => $params,
            'successUrl' => $this->siteUrl($settings['successUrl'] ?? 'checkout/success?session_id={CHECKOUT_SESSION_ID}'),
            'cancelUrl' => $this->siteUrl($settings['cancelUrl'] ?? 'cart'),
        ]);
        $this->trigger(self::EVENT_BEFORE_CHECKOUT, $event);

        return StripePlugin::getInstance()->getCheckout()->getCheckoutUrl(
            $event->lineItems,
            null,
            $event->successUrl,
            $event->cancelUrl,
            $event->params ?: null,
        );
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
