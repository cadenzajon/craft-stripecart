<?php

namespace cadenzajon\stripecart\events;

use craft\stripe\elements\Product;
use yii\base\Event;

/**
 * Fired when the cart needs to know whether a product may be purchased, at add
 * time and when building checkout line items. The cart plugin is store-agnostic,
 * so a site module decides availability (e.g. by a book status field) and sets
 * `isEligible = false` with a customer-facing `reason`.
 */
class EligibilityEvent extends Event
{
    /** The product being added or checked out. */
    public Product $product;

    /** The quantity requested. */
    public int $qty = 1;

    /** Whether the product may be purchased. Handlers set false to block it. */
    public bool $isEligible = true;

    /** Optional customer-facing reason shown when not eligible. */
    public ?string $reason = null;
}
