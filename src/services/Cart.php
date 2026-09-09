<?php

namespace cadenzajon\stripecart\services;

use cadenzajon\stripecart\events\EligibilityEvent;
use cadenzajon\stripecart\exceptions\CartException;
use cadenzajon\stripecart\models\CartItem;
use cadenzajon\stripecart\Plugin;
use Craft;
use craft\stripe\elements\Product;
use yii\base\Component;

/**
 * Session cart. The browser only ever supplies product IDs and quantities;
 * prices are resolved server-side from the visitor's tier, and every product is
 * checked for eligibility (via EVENT_ELIGIBILITY) at add and checkout time.
 */
class Cart extends Component
{
    /**
     * Fired to decide whether a product may be purchased. A site module sets
     * `isEligible = false` (e.g. for an out-of-stock or unreleased product).
     */
    public const EVENT_ELIGIBILITY = 'eligibility';

    /** A Stripe Checkout Session accepts at most 100 line items (one per product). */
    private const STRIPE_MAX_LINE_ITEMS = 100;

    private const SESSION_KEY = 'stripe-cart:cart';
    private const CURRENCY_SESSION_KEY = 'stripe-cart:currency';

    /** @var CartItem[]|null Normalized rows for the current request. */
    private ?array $hydratedItems = null;

    /** @var array<int, string> Notices for quantities clamped during hydration. */
    private array $clampNotices = [];

    /**
     * @return array<int, int> productId => qty
     */
    public function getItems(): array
    {
        return Craft::$app->getSession()->get(self::SESSION_KEY, []);
    }

    /**
     * @return array{qty: int, capped: bool}
     * @throws CartException if the product is unavailable, missing, or the cart
     *   is at its limits.
     */
    public function add(int $productId, int $qty = 1): array
    {
        $qty = max(1, $qty);
        $this->getHydratedItems();
        $items = $this->getItems();

        if (!isset($items[$productId]) && count($items) >= self::STRIPE_MAX_LINE_ITEMS) {
            throw new CartException('Your cart is full. Please check out or remove an item first.');
        }

        $product = $this->requireProduct($productId);
        $currency = $this->assertProductCurrency($product);
        $requestedQty = ($items[$productId] ?? 0) + $qty;
        $newQty = $this->clampQty($requestedQty, $product);
        $this->assertEligible($product, $newQty);

        $items[$productId] = $newQty;
        $this->setItems($items);
        Craft::$app->getSession()->set(self::CURRENCY_SESSION_KEY, $currency);

        return ['qty' => $newQty, 'capped' => $newQty < $requestedQty];
    }

    /**
     * @return array{qty: int, capped: bool}
     * @throws CartException if the product is unavailable or missing.
     */
    public function update(int $productId, int $qty): array
    {
        if ($qty <= 0) {
            $items = $this->getItems();
            unset($items[$productId]);
            $this->setItems($items);
            return ['qty' => 0, 'capped' => false];
        }

        $this->getHydratedItems();
        $items = $this->getItems();
        if (!isset($items[$productId]) && count($items) >= self::STRIPE_MAX_LINE_ITEMS) {
            throw new CartException('Your cart is full. Please check out or remove an item first.');
        }

        $product = $this->requireProduct($productId);
        $currency = $this->assertProductCurrency($product);
        $requestedQty = $qty;
        $qty = $this->clampQty($requestedQty, $product);
        $this->assertEligible($product, $qty);

        $items[$productId] = $qty;
        $this->setItems($items);
        Craft::$app->getSession()->set(self::CURRENCY_SESSION_KEY, $currency);

        return ['qty' => $qty, 'capped' => $qty < $requestedQty];
    }

    public function remove(int $productId): void
    {
        $this->update($productId, 0);
    }

    public function clear(): void
    {
        Craft::$app->getSession()->remove(self::SESSION_KEY);
        Craft::$app->getSession()->remove(self::CURRENCY_SESSION_KEY);
        $this->hydratedItems = null;
        $this->clampNotices = [];
    }

    /**
     * @return CartItem[]
     */
    public function getHydratedItems(): array
    {
        if ($this->hydratedItems !== null) {
            return $this->hydratedItems;
        }

        $tiers = Plugin::getInstance()->tiers;
        $stored = $this->getItems();
        $normalized = [];
        $items = [];
        $currency = Craft::$app->getSession()->get(self::CURRENCY_SESSION_KEY);
        $currency = is_string($currency) && $currency !== '' ? strtolower($currency) : null;

        foreach ($stored as $productId => $qty) {
            $product = Product::find()->id($productId)->one();
            if (!$product) {
                continue;
            }
            $storedQty = (int)$qty;
            $qty = $this->clampQty($storedQty, $product);
            if ($qty < $storedQty) {
                $title = trim((string)$product->title) ?: 'Item';
                $this->clampNotices[(int)$productId] = "{$title}: limited to {$qty} available.";
            }
            if (!$this->isEligible($product, $qty)) {
                continue;
            }
            $price = $tiers->resolvePrice($product);
            if (!$price) {
                continue;
            }
            $priceCurrency = strtolower((string)($price->getData()['currency'] ?? 'usd'));
            if ($currency === null) {
                $currency = $priceCurrency;
                Craft::$app->getSession()->set(self::CURRENCY_SESSION_KEY, $currency);
            }
            if ($priceCurrency !== $currency) {
                continue;
            }
            $normalized[$productId] = $qty;
            $sale = Plugin::getInstance()->sales->resolve($product, $price);
            $items[] = new CartItem($product, $price, $qty, $sale);
        }

        if ($normalized !== $stored) {
            $this->setItems($normalized);
        }
        // JSON actions carry the notice in their response; do not leave a
        // duplicate flash behind for an unrelated later page.
        if (!Craft::$app->getRequest()->getAcceptsJson() && ($notice = $this->getClampNotice())) {
            Craft::$app->getSession()->setNotice($notice);
        }

        return $this->hydratedItems = $items;
    }

    /**
     * Fires EVENT_ELIGIBILITY and throws if a handler blocks the product.
     *
     * @throws CartException
     */
    public function assertEligible(Product $product, int $qty = 1): void
    {
        $event = new EligibilityEvent([
            'product' => $product,
            'qty' => $qty,
        ]);
        $this->trigger(self::EVENT_ELIGIBILITY, $event);

        if (!$event->isEligible) {
            throw new CartException($event->reason ?? 'This product is not available for purchase.');
        }
    }

    public function isEligible(Product $product, int $qty = 1): bool
    {
        try {
            $this->assertEligible($product, $qty);
            return true;
        } catch (CartException) {
            return false;
        }
    }

    /**
     * @throws CartException if the product does not exist.
     */
    private function requireProduct(int $productId): Product
    {
        $product = Product::find()->id($productId)->one();
        if (!$product) {
            throw new CartException('That product is not available.');
        }
        return $product;
    }

    /**
     * The per-item maximum quantity, read from the product's Stripe metadata
     * (0 = no limit). Falls back to the configured default cap.
     */
    public function maxQtyFor(Product $product): int
    {
        $key = Plugin::getInstance()->getSettings()->maxQtyMetadataKey;
        if ($key === '') {
            return 0;
        }

        $max = $product->getData()['metadata'][$key] ?? null;
        if (is_numeric($max)) {
            return max(0, (int)$max);
        }

        return max(0, Plugin::getInstance()->getSettings()->defaultMaxQty);
    }

    /** Clamps a quantity to at least 1 and at most the product's per-item limit. */
    private function clampQty(int $qty, Product $product): int
    {
        $qty = max(1, $qty);
        $max = $this->maxQtyFor($product);
        return $max > 0 ? min($max, $qty) : $qty;
    }

    public function getCount(): int
    {
        return array_sum($this->getItems());
    }

    public function getIsEmpty(): bool
    {
        return $this->getItems() === [];
    }

    /**
     * Stripe Checkout line items. A product without a sale is sent as its
     * tier-resolved price ID; a discounted product is sent as inline price_data
     * at the sale amount, so the charge matches the price shown on the site.
     *
     * @return array<array<string, mixed>>
     */
    public function getLineItems(): array
    {
        $sales = Plugin::getInstance()->sales;
        $items = $this->getHydratedItems();
        if ($notice = $this->getClampNotice()) {
            throw new CartException($notice . ' Review your cart before checking out.');
        }

        return array_map(
            fn(CartItem $item) => $sales->lineItem($item),
            $items,
        );
    }

    /** The single currency selected by the cart's resolved prices. */
    public function getCurrency(): string
    {
        $items = $this->getHydratedItems();
        $currency = Craft::$app->getSession()->get(self::CURRENCY_SESSION_KEY);

        return $items !== [] && is_string($currency) ? $currency : 'usd';
    }

    private function assertProductCurrency(Product $product): string
    {
        $price = Plugin::getInstance()->tiers->resolvePrice($product);
        if (!$price) {
            throw new CartException('This product does not have a price for the current cart.');
        }

        $currency = strtolower((string)($price->getData()['currency'] ?? 'usd'));
        $existingCurrency = Craft::$app->getSession()->get(self::CURRENCY_SESSION_KEY);
        if (is_string($existingCurrency) && $currency !== strtolower($existingCurrency)) {
            throw new CartException('This product uses a different currency from the current cart.');
        }

        return $currency;
    }

    public function getClampNotice(?int $exceptProductId = null): ?string
    {
        $notices = $this->clampNotices;
        if ($exceptProductId !== null) {
            unset($notices[$exceptProductId]);
        }

        return $notices === [] ? null : implode(' ', $notices);
    }

    private function setItems(array $items): void
    {
        Craft::$app->getSession()->set(self::SESSION_KEY, $items);
        if ($items === []) {
            Craft::$app->getSession()->remove(self::CURRENCY_SESSION_KEY);
        }
        $this->hydratedItems = null;
    }
}
