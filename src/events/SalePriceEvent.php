<?php

namespace cadenzajon\stripecart\events;

use craft\stripe\elements\Price;
use craft\stripe\elements\Product;
use yii\base\Event;

/**
 * Fired when the cart needs to know whether a product is on sale. The cart
 * plugin is store-agnostic, so a site module decides the discount (e.g. from a
 * "Sale %" field) by setting `percentOff`.
 */
class SalePriceEvent extends Event
{
    /** The product being priced. */
    public Product $product;

    /** The tier-resolved price the discount applies to. */
    public Price $price;

    /**
     * Percent off the list price, 0–100. Null or 0 means no sale. Handlers set
     * this; values outside the range are ignored.
     */
    public ?float $percentOff = null;
}
