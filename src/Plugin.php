<?php

namespace cadenzajon\stripecart;

use cadenzajon\stripecart\events\OrderCompletedEvent;
use cadenzajon\stripecart\models\Settings;
use cadenzajon\stripecart\services\Cart;
use cadenzajon\stripecart\services\Checkout;
use cadenzajon\stripecart\services\Sales;
use cadenzajon\stripecart\services\Tiers;
use cadenzajon\stripecart\variables\StripeCartVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\stripe\events\StripeEvent;
use craft\stripe\services\Webhooks as StripeWebhooks;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use yii\base\Event;

/**
 * @property-read Cart $cart
 * @property-read Tiers $tiers
 * @property-read Checkout $checkout
 * @property-read Sales $sales
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'cart' => Cart::class,
                'tiers' => Tiers::class,
                'checkout' => Checkout::class,
                'sales' => Sales::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'cadenzajon\\stripecart\\console\\controllers';
        } else {
            $this->controllerNamespace = 'cadenzajon\\stripecart\\controllers';
        }

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('stripeCart', StripeCartVariable::class);
        });

        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
            $event->roots['_stripe-cart'] = __DIR__ . '/templates';
        });

        // The official plugin receives all webhooks; expose checkout lifecycle
        // and payment-state events to this plugin's consumers.
        Event::on(StripeWebhooks::class, StripeWebhooks::EVENT_STRIPE_EVENT, function(StripeEvent $event) {
            $type = $event->stripeEvent->type;
            $session = $event->stripeEvent->data->object;

            if ($type === 'checkout.session.completed') {
                $this->checkout->trigger(Checkout::EVENT_ORDER_COMPLETED, new OrderCompletedEvent([
                    'session' => $session,
                ]));

                if (($session->payment_status ?? null) !== 'unpaid') {
                    $this->checkout->trigger(Checkout::EVENT_ORDER_PAID, new OrderCompletedEvent(['session' => $session]));
                }
            } elseif ($type === 'checkout.session.async_payment_succeeded') {
                $this->checkout->trigger(Checkout::EVENT_ORDER_PAID, new OrderCompletedEvent(['session' => $session]));
            } elseif ($type === 'checkout.session.async_payment_failed') {
                $this->checkout->trigger(Checkout::EVENT_ORDER_PAYMENT_FAILED, new OrderCompletedEvent(['session' => $session]));
            }
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
