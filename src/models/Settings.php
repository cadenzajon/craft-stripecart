<?php

namespace cadenzajon\stripecart\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Optional pricing tiers, keyed by handle. Empty (the default) means no
     * tiers: every product sells at its Stripe default price.
     *
     * Options per tier:
     * - default: bool — the tier applied to all visitors
     * - accessCode: string — passcode that activates the tier for a session
     * - userGroup: string — Craft user group handle that activates the tier (Pro)
     *
     * @var array<string, array>
     */
    public array $tiers = [];

    /** Stripe price metadata key that names the tier a price belongs to. */
    public string $priceTierMetadataKey = 'tier';

    /** Maximum quantity of a single product per cart line (0 = no limit). */
    public int $maxQtyPerItem = 99;

    /** Maximum number of distinct products in the cart (0 = no limit). */
    public int $maxDistinctItems = 50;

    /**
     * Checkout options, all optional:
     * - successUrl: string — site path, may include {CHECKOUT_SESSION_ID}
     * - cancelUrl: string — site path
     * - shippingCountries: string[] — ISO country codes; enables shipping address collection
     * - shippingOptions: string[] — pre-created Stripe shipping rate IDs (shr_...) to offer
     * - shippingRates: array[] — inline shipping_rate_data definitions to offer (an
     *     alternative to shippingOptions; each entry is passed as shipping_rate_data)
     * - automaticTax: bool — enable Stripe Tax (automatic_tax) on the Checkout Session
     * - allowPromotionCodes: bool
     *
     * @var array<string, mixed>
     */
    public array $checkout = [];

    public function getDefaultTier(): string
    {
        foreach ($this->tiers as $handle => $config) {
            if (!empty($config['default'])) {
                return $handle;
            }
        }

        return array_key_first($this->tiers) ?? '';
    }
}
