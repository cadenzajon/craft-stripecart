<?php

namespace cadenzajon\stripecart\models;

use craft\stripe\elements\Price;
use craft\stripe\elements\Product;

/**
 * A hydrated cart row: the synced product element, the tier-resolved price
 * element, the quantity, and the sale when the product is discounted.
 */
class CartItem
{
    public function __construct(
        public Product $product,
        public Price $price,
        public int $qty,
        public ?SalePrice $sale = null,
    ) {
    }

    /**
     * True when this line has a single exact per-unit amount that Stripe will
     * bill. Tiered, quantity-transformed and customer-chosen prices are billed
     * by rules the cart cannot reproduce, so their totals must not be shown as
     * if they were exact.
     */
    public function getIsAmountExact(): bool
    {
        if ($this->sale) {
            return true;
        }

        $data = $this->price->getData();
        $amount = $data['unit_amount'] ?? null;

        return is_numeric($amount)
            && (float)$amount === (float)(int)$amount
            && empty($data['tiers'])
            && empty($data['transform_quantity'])
            && empty($data['custom_unit_amount']);
    }

    /**
     * The unit amount actually charged, in the currency's smallest unit, or 0
     * when the price has no single exact amount (see getIsAmountExact()).
     */
    public function getUnitAmount(): int
    {
        if ($this->sale) {
            return $this->sale->saleAmount;
        }

        return $this->getIsAmountExact() ? (int)$this->price->getData()['unit_amount'] : 0;
    }

    /** The line total actually charged, in the currency's smallest unit. */
    public function getLineAmount(): int
    {
        return $this->getUnitAmount() * $this->qty;
    }
}
