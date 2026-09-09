<?php

namespace cadenzajon\stripecart\variables;

use cadenzajon\stripecart\models\CartItem;
use cadenzajon\stripecart\models\SalePrice;
use cadenzajon\stripecart\Plugin;
use Craft;
use craft\stripe\elements\Price;
use craft\stripe\elements\Product;

/**
 * Available in Twig as craft.stripeCart.
 */
class StripeCartVariable
{
    /**
     * @return CartItem[]
     */
    public function getItems(): array
    {
        $items = Plugin::getInstance()->cart->getHydratedItems();
        $this->showClampNotice();
        return $items;
    }

    public function getCount(): int
    {
        return Plugin::getInstance()->cart->getCount();
    }

    public function getIsEmpty(): bool
    {
        return Plugin::getInstance()->cart->getIsEmpty();
    }

    /** The active pricing tier handle for this session. */
    public function getTier(): string
    {
        return Plugin::getInstance()->tiers->getActiveTier();
    }

    /** The price a product sells at in the session's active tier. */
    public function priceFor(Product $product): ?Price
    {
        return Plugin::getInstance()->tiers->resolvePrice($product);
    }

    /**
     * The sale on a product, or null when it is not discounted. Exposes the
     * original price, the sale price, and the percent off for display.
     */
    public function saleFor(Product $product, ?Price $price = null): ?SalePrice
    {
        return Plugin::getInstance()->sales->resolve($product, $price);
    }

    /** The cart total actually charged, in the currency's smallest unit. */
    public function getSubtotal(): int
    {
        $total = 0;
        foreach ($this->getItems() as $item) {
            $total += $item->getLineAmount();
        }

        return $total;
    }

    /**
     * True when every line has an exact per-unit amount, so the displayed
     * subtotal is the amount Stripe will charge.
     */
    public function getHasExactTotal(): bool
    {
        foreach ($this->getItems() as $item) {
            if (!$item->getIsAmountExact()) {
                return false;
            }
        }

        return true;
    }

    /** The cart session's currency, taken from its resolved prices. */
    public function getCurrency(): string
    {
        $currency = Plugin::getInstance()->cart->getCurrency();
        $this->showClampNotice();
        return $currency;
    }

    /**
     * Formats a Stripe amount for display, honouring zero-decimal currencies.
     */
    public function formatAmount(int $amount, ?string $currency = null): string
    {
        return Plugin::getInstance()->sales->format($amount, $currency ?? $this->getCurrency());
    }

    private function showClampNotice(): void
    {
        if (!Craft::$app->getRequest()->getAcceptsJson()
            && ($notice = Plugin::getInstance()->cart->getClampNotice())) {
            Craft::$app->getSession()->setNotice($notice);
        }
    }
}
