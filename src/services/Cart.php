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
     * `isEligible = false` (e.g. for an out-of-stock or coming-soon title).
     */
    public const EVENT_ELIGIBILITY = 'eligibility';

    private const SESSION_KEY = 'stripe-cart:cart';

    /**
     * @return array<int, int> productId => qty
     */
    public function getItems(): array
    {
        return Craft::$app->getSession()->get(self::SESSION_KEY, []);
    }

    /**
     * @throws CartException if the product is unavailable, missing, or the cart
     *   is at its limits.
     */
    public function add(int $productId, int $qty = 1): void
    {
        $qty = max(1, $qty);
        $items = $this->getItems();

        $settings = Plugin::getInstance()->getSettings();
        if ($settings->maxDistinctItems > 0
            && !isset($items[$productId])
            && count($items) >= $settings->maxDistinctItems) {
            throw new CartException('Your cart is full. Please check out or remove an item first.');
        }

        $product = $this->requireProduct($productId);
        $newQty = $this->clampQty(($items[$productId] ?? 0) + $qty, $product);
        $this->assertEligible($product, $newQty);

        $items[$productId] = $newQty;
        $this->setItems($items);
    }

    /**
     * @throws CartException if the product is unavailable or missing.
     */
    public function update(int $productId, int $qty): void
    {
        $items = $this->getItems();
        if ($qty <= 0) {
            unset($items[$productId]);
            $this->setItems($items);
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        if ($settings->maxDistinctItems > 0
            && !isset($items[$productId])
            && count($items) >= $settings->maxDistinctItems) {
            throw new CartException('Your cart is full. Please check out or remove an item first.');
        }

        $product = $this->requireProduct($productId);
        $qty = $this->clampQty($qty, $product);
        $this->assertEligible($product, $qty);

        $items[$productId] = $qty;
        $this->setItems($items);
    }

    public function remove(int $productId): void
    {
        $this->update($productId, 0);
    }

    public function clear(): void
    {
        Craft::$app->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * @return CartItem[]
     */
    public function getHydratedItems(): array
    {
        $tiers = Plugin::getInstance()->tiers;
        $items = [];

        foreach ($this->getItems() as $productId => $qty) {
            $product = Product::find()->id($productId)->one();
            if (!$product) {
                continue;
            }
            $qty = $this->clampQty((int)$qty, $product);
            // Drop anything that is no longer purchasable so a stale cart cannot
            // check out an out-of-stock or coming-soon title.
            if (!$this->isEligible($product, $qty)) {
                continue;
            }
            $price = $tiers->resolvePrice($product);
            if (!$price) {
                continue;
            }
            $items[] = new CartItem($product, $price, $qty);
        }

        return $items;
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
            throw new CartException($event->reason ?? 'This title is not available for purchase.');
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
            throw new CartException('That title is not available.');
        }
        return $product;
    }

    /**
     * The per-item maximum quantity, read from the product's Stripe metadata
     * (0 = no limit).
     */
    public function maxQtyFor(Product $product): int
    {
        $key = Plugin::getInstance()->getSettings()->maxQtyMetadataKey;
        if ($key === '') {
            return 0;
        }
        $max = $product->getData()['metadata'][$key] ?? null;
        return is_numeric($max) ? max(0, (int)$max) : 0;
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
     * Stripe Checkout line items with tier-resolved price IDs.
     *
     * @return array<array{price: string, quantity: int}>
     */
    public function getLineItems(): array
    {
        return array_map(
            fn(CartItem $item) => ['price' => $item->price->stripeId, 'quantity' => $item->qty],
            $this->getHydratedItems(),
        );
    }

    private function setItems(array $items): void
    {
        Craft::$app->getSession()->set(self::SESSION_KEY, $items);
    }
}
