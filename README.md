# Stripe Cart for Craft CMS

A session cart and Stripe Checkout for Craft CMS 5. Your products and prices live in Stripe; this plugin syncs them into Craft, holds a cart in the session, and hands off to Stripe Checkout. No Craft Commerce license, no JavaScript framework, no build step.

It builds on the free [`craftcms/stripe`](https://plugins.craftcms.com/stripe) plugin, which syncs your catalog. This plugin adds the cart and the checkout.

## Requirements

- Craft CMS 5.6+
- [`craftcms/stripe`](https://plugins.craftcms.com/stripe) 1.3+
- PHP 8.2+
- A Stripe account

## Install

```bash
composer require cadenzajon/craft-stripecart
php craft plugin/install stripe-cart
```

Set your Stripe secret key in `.env` (the official plugin reads it):

```bash
STRIPE_SECRET_KEY=sk_test_...
```

## Quick start

Three steps: sync your catalog, add a cart button, add a checkout button. No configuration required. The verified return uses Craft's configured action URL and provides a minimal confirmation page. If `templates/checkout/success.twig` exists, the plugin renders it instead; set `successTemplate` in `config/stripe-cart.php` to select another site template explicitly.

**1. Sync products and prices from Stripe:**

```bash
php craft stripe-cart/sync
```

**2. Add-to-cart button on a product page** (products are `craftcms/stripe` elements):

```twig
<form method="post">
  {{ csrfInput() }}
  {{ actionInput('stripe-cart/cart/add') }}
  {{ hiddenInput('productId', product.id) }}
  <button>Add to cart</button>
</form>
```

**3. A cart page with a checkout button:**

```twig
{% for item in craft.stripeCart.items %}
  {{ item.product.title }} × {{ item.qty }}
{% endfor %}

<form method="post">
  {{ csrfInput() }}
  {{ actionInput('stripe-cart/checkout') }}
  <button>Check out</button>
</form>
```

That's the whole store. Each product sells at its default Stripe price, and Stripe Checkout handles payment, address, and shipping.

Currency is selected once for the whole cart session from its first resolved Stripe price; it is never stored independently on cart rows. Adding a product with no resolved price or in another currency is rejected. If a catalog or tier change makes a stored row disagree with the cart currency, that stale row is silently purged before display or checkout.

## Cart

`craft.stripeCart` in Twig:

- `items` — cart rows, each with `product`, `price`, and `qty`
- `count` — total quantity
- `isEmpty`

Missing, ineligible, unpriced, and stale currency-mismatched rows are silently purged when hydrated cart contents are read, and over-limit quantities are normalized. Add/update normalizes the cart before enforcing Stripe's 100-line limit, so invisible stale rows cannot fill the cart.

Actions (POST `productId` and `qty`; they redirect back, or return JSON when the request sends `Accept: application/json`):

- `stripe-cart/cart/add`
- `stripe-cart/cart/update`
- `stripe-cart/cart/remove`
- `stripe-cart/cart/clear`

## Checkout

`stripe-cart/checkout` turns the cart into a Stripe Checkout Session and redirects the customer to Stripe. After payment they return to your success URL, and the cart clears.

Everything below is optional. Configure it in `config/stripe-cart.php`:

```php
return [
    'successTemplate' => 'shop/thanks', // optional site template override
    'defaultMaxQty' => 5,                    // fallback per-product cap; 0 = unlimited
    'maxQtyMetadataKey' => 'max_qty',        // Stripe Product metadata override
    'checkout' => [
        'cancelUrl' => 'shop/cart',
        'shippingCountries' => ['US', 'CA'],   // collect a shipping address
        'shippingOptions' => ['shr_123'],       // Stripe shipping rate IDs
        'allowPromotionCodes' => true,
    ],
];
```

By default the success return uses Craft's action URL so verification always runs. To keep a pretty `/checkout/success` URL, add a site route in `config/routes.php` and then set the matching URL:

```php
// config/routes.php
return [
    'checkout/success' => 'stripe-cart/checkout/success',
];

// config/stripe-cart.php, inside checkout
'successUrl' => 'checkout/success?session_id={CHECKOUT_SESSION_ID}',
```

Do not point `successUrl` at a plain template or entry route; that bypasses payment verification and cart clearing.

Quantity requests above the product's `max_qty` metadata value are clamped with a visible “Limited to N available” message. Products without numeric metadata use `defaultMaxQty`, which defaults to 5. Explicit product metadata `max_qty=0` makes that product unlimited. Set `defaultMaxQty` to `0` for an unlimited fallback, or set `maxQtyMetadataKey` to `''` to preserve the previous unlimited behavior and disable all quantity caps.

JSON responses from add and update include the effective `qty` and a boolean `capped` value in addition to `message` and the total cart `count`.

Two events let a site module hook in:

- `beforeCheckout` — modify the line items or session params before they go to Stripe
- `orderCompleted` — fires on the `checkout.session.completed` webhook, with the session payload

## Shipping and tax

Stripe handles both; the plugin computes neither.

Create shipping rates in the [Stripe Dashboard](https://dashboard.stripe.com/shipping-rates) (flat amounts, free shipping, delivery estimates), then list their IDs in `checkout.shippingOptions`. Stripe shows them at checkout, the customer picks one, and the cost is added to the total. Enable [Stripe Tax](https://docs.stripe.com/tax/checkout) for automatic tax, including tax on shipping.

Stripe's built-in shipping rates are a fixed amount per order. If you need rates that change with the delivery address or order total (for example free shipping over $50, or weight-based pricing), compute a rate in a `beforeCheckout` handler — your handler has the cart and can set `shipping_options` on the session params. Stripe's own [dynamic shipping options](https://docs.stripe.com/payments/checkout/custom-shipping-options) go further but require embedded checkout rather than the hosted redirect this plugin uses.

## Sync

```bash
php craft stripe-cart/sync
```

Pulls every product and price from Stripe. Safe to re-run. To keep the catalog current automatically, subscribe a webhook once:

```bash
php craft stripe-cart/webhooks/subscribe https://your-site.com/stripe/webhooks/handle
```

This creates the endpoint on Stripe and stores the signing secret where the official plugin expects it. Re-running the command updates the saved endpoint's URL and event list; if that endpoint no longer exists in Stripe, it creates a replacement. Also available: `stripe-cart/webhooks/status` and `stripe-cart/webhooks/unsubscribe`.

The endpoint includes immediate Checkout completion plus asynchronous payment success and failure events. Applications should fulfill from `Checkout::EVENT_ORDER_PAID`, which covers both immediate and delayed success, rather than from an initially unpaid completion event. Do not fulfill from both `EVENT_ORDER_COMPLETED` and `EVENT_ORDER_PAID`, because both fire for an immediately paid checkout. Webhooks can be retried, so handlers must be idempotent.

Existing Stripe endpoints are not changed merely by updating the package. Re-run `stripe-cart/webhooks/subscribe` after upgrading so the saved endpoint receives the new asynchronous events.

## Pricing tiers (optional)

By default there are no tiers: every product sells at its Stripe default price. Turn tiers on only when a product needs more than one price for different customers — for example retail and wholesale.

Give each product one price per tier in Stripe, tagged with price metadata (default key `tier`):

- the retail price gets metadata `tier` = `retail`
- the wholesale price gets metadata `tier` = `wholesale`

Then define the tiers in `config/stripe-cart.php`:

```php
return [
    'tiers' => [
        'retail' => ['default' => true],
        'wholesale' => ['accessCode' => getenv('WHOLESALE_CODE')],
    ],
];
```

The `default` tier applies to everyone. Other tiers activate per session in one of three ways:

- **Access code** — POST to `stripe-cart/tiers/activate` with an `accessCode` field. Works on Craft Solo, which has no front-end users. POST to `stripe-cart/tiers/deactivate` to revert.
- **User group** (Craft Pro) — add `'userGroup' => 'trade'` to a tier; members of that group get it automatically.
- **`resolveTier` event** — for anything else (IP allowlist, signed URL, time window).

In Twig, `craft.stripeCart.tier` is the active tier handle and `craft.stripeCart.priceFor(product)` returns the tier-resolved price. Checkout uses the active tier's prices automatically.

To store the tier on a different metadata key, set `priceTierMetadataKey`.

## Events

- `cadenzajon\stripecart\services\Tiers::EVENT_RESOLVE_TIER` — override the resolved pricing tier
- `cadenzajon\stripecart\services\Checkout::EVENT_BEFORE_CHECKOUT` — modify line items and session params
- `cadenzajon\stripecart\services\Checkout::EVENT_ORDER_COMPLETED` — a checkout completed (from the webhook)
- `cadenzajon\stripecart\services\Checkout::EVENT_ORDER_PAID` — an immediate or delayed checkout payment succeeded
- `cadenzajon\stripecart\services\Checkout::EVENT_ORDER_PAYMENT_FAILED` — a delayed checkout payment failed

## License

[MIT](LICENSE)
