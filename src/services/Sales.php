<?php

namespace cadenzajon\stripecart\services;

use cadenzajon\stripecart\events\SalePriceEvent;
use cadenzajon\stripecart\models\CartItem;
use cadenzajon\stripecart\models\SalePrice;
use cadenzajon\stripecart\Plugin;
use Craft;
use craft\stripe\elements\Price;
use craft\stripe\elements\Product;
use yii\base\Component;

/**
 * Resolves per-product sales. The discount itself comes from a site module via
 * EVENT_RESOLVE_SALE, so the plugin stays store-agnostic. The same resolved
 * amount drives both the storefront display and the Stripe checkout line, so
 * what a visitor sees is what they are charged.
 */
class Sales extends Component
{
    /**
     * Fired to resolve a product's discount. Handlers set
     * `percentOff` (0–100); null or 0 means no sale.
     */
    public const EVENT_RESOLVE_SALE = 'resolveSale';

    /**
     * Currencies Stripe holds with no minor unit, so their amounts are already
     * whole units and must not be divided by 100.
     */
    private const ZERO_DECIMAL = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    /**
     * Formats a Stripe amount (in the currency's smallest unit) for display,
     * without the zero-decimal currencies being divided by 100.
     */
    public function format(int $amount, string $currency = 'usd'): string
    {
        $value = in_array(strtolower($currency), self::ZERO_DECIMAL, true)
            ? $amount
            : $amount / 100;

        return Craft::$app->getFormatter()->asCurrency($value, $currency);
    }

    /**
     * The sale on a product, or null when it is not discounted.
     */
    public function resolve(Product $product, ?Price $price = null): ?SalePrice
    {
        $price ??= Plugin::getInstance()->tiers->resolvePrice($product);
        if (!$price) {
            return null;
        }

        $data = $price->getData();

        // Sales apply to one-time prices only. A recurring price would change
        // the checkout mode, and subscription discounts belong on a coupon.
        if (($data['type'] ?? 'one_time') !== 'one_time') {
            return null;
        }

        // Only a plain per-unit amount can be discounted safely. Tiered,
        // quantity-transformed, customer-chosen, and sub-minor-unit decimal
        // prices are billed by rules this cannot reproduce, so discounting them
        // would risk charging an amount that differs from the one displayed.
        if (!empty($data['tiers'])
            || !empty($data['transform_quantity'])
            || !empty($data['custom_unit_amount'])) {
            return null;
        }

        $original = $data['unit_amount'] ?? null;
        // Must be a whole amount in the currency's smallest unit; a fractional
        // value would be truncated and charged differently from the display.
        if ($original === null
            || !is_numeric($original)
            || (float)$original !== (float)(int)$original) {
            // Customer-chosen or otherwise unpriced; nothing to discount.
            return null;
        }

        // A decimal amount finer than the minor unit cannot round-trip exactly.
        $decimal = $data['unit_amount_decimal'] ?? null;
        if ($decimal !== null && (float)$decimal !== (float)(int)$original) {
            return null;
        }

        $event = new SalePriceEvent([
            'product' => $product,
            'price' => $price,
        ]);
        $this->trigger(self::EVENT_RESOLVE_SALE, $event);

        $percent = $event->percentOff;
        if ($percent === null || $percent <= 0 || $percent >= 100) {
            return null;
        }

        $original = (int)$original;
        $sale = $this->discount($original, $percent);
        // A sale must be a real, chargeable amount below the list price. A
        // rounded-to-zero result would show a blank price and charge nothing.
        if ($sale <= 0 || $sale >= $original) {
            return null;
        }

        return new SalePrice($product, $price, (int)round($percent), $original, $sale);
    }

    /**
     * Applies a percentage to a Stripe amount. Stripe amounts are always whole
     * numbers in the currency's smallest unit, so the result is rounded to an
     * integer and the charged amount is exact.
     */
    public function discount(int $amount, float $percent): int
    {
        return max(0, (int)round($amount * (100 - $percent) / 100));
    }

    /**
     * The Stripe Checkout line item for a cart row: a plain price reference, or
     * inline price_data at the sale amount when the product is discounted. The
     * sale is taken from the row, so it is resolved once per request.
     *
     * @return array<string, mixed>
     */
    public function lineItem(CartItem $item): array
    {
        $price = $item->price;
        $sale = $item->sale;
        $qty = $item->qty;

        if (!$sale) {
            return ['price' => $price->stripeId, 'quantity' => $qty];
        }

        $data = $price->getData();
        $priceData = [
            'currency' => $data['currency'] ?? 'usd',
            'unit_amount' => $sale->saleAmount,
            // Keep the Stripe product linked so Checkout shows its name and
            // image, and fulfillment still sees the product id.
            'product' => $item->product->stripeId,
        ];

        // Carry an explicit tax behavior across; otherwise the account default
        // applies, exactly as it does for the list price.
        $behavior = $data['tax_behavior'] ?? null;
        if (is_string($behavior) && $behavior !== '' && $behavior !== 'unspecified') {
            $priceData['tax_behavior'] = $behavior;
        }

        return ['price_data' => $priceData, 'quantity' => $qty];
    }
}
