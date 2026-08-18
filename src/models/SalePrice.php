<?php

namespace cadenzajon\stripecart\models;

use cadenzajon\stripecart\Plugin;
use craft\stripe\elements\Price;
use craft\stripe\elements\Product;

/**
 * A resolved sale on a product: the list price, the discounted price, and the
 * percent off. Amounts are in the currency's smallest unit, matching Stripe,
 * and are formatted with the same currency rules as a list price.
 */
class SalePrice
{
    public function __construct(
        public Product $product,
        public Price $price,
        public int $percentOff,
        public int $originalAmount,
        public int $saleAmount,
    ) {
    }

    /** The list price, formatted (e.g. "$21.99"). */
    public function getOriginal(): string
    {
        return $this->format($this->originalAmount);
    }

    /** The sale price, formatted (e.g. "$17.59"). */
    public function getSale(): string
    {
        return $this->format($this->saleAmount);
    }

    /** The amount saved, formatted. */
    public function getSaved(): string
    {
        return $this->format(max(0, $this->originalAmount - $this->saleAmount));
    }

    private function format(int $amount): string
    {
        return Plugin::getInstance()->sales->format($amount, $this->getCurrency());
    }

    public function getCurrency(): string
    {
        return (string)($this->price->getData()['currency'] ?? 'usd');
    }
}
