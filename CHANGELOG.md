# Release Notes for Stripe Cart

## Unreleased

### Added
- A zero-configuration, verified checkout return through Craft's action URL.
- A private fallback success template and the `successTemplate` site-template override.
- Stripe webhook subscriptions now include delayed Checkout payment success and failure events.
- `Checkout::EVENT_ORDER_PAID` covers successful immediate and delayed payments; `Checkout::EVENT_ORDER_PAYMENT_FAILED` reports delayed failures.
- Cart sessions enforce one currency across all resolved prices without storing currency on individual cart rows.

### Breaking change
- Products without numeric quantity metadata now use a configurable `defaultMaxQty` of 5 rather than remaining unlimited. Set it to `0`, or set `maxQtyMetadataKey` to `''`, before upgrading to retain unlimited quantities. Explicit product metadata `max_qty=0` also keeps that product unlimited.

### Changed
- The default post-payment URL now uses Craft's action URL. Sites that track the previous `/checkout/success` path can preserve it by routing that path to `stripe-cart/checkout/success` and setting `checkout.successUrl` explicitly.
- Hydrated cart reads silently purge missing, ineligible, unpriced, and stale currency-mismatched rows and persist clamped quantities. Add/update normalizes stale rows before enforcing the 100-line limit.
- Add/update responses visibly report clamping and JSON responses include the effective `qty` and `capped` state.

### Fixed
- Stripe API and network failures now return a controlled customer-safe checkout error while logging diagnostic details.

### Upgrade note
- Re-run `stripe-cart/webhooks/subscribe` to add the new event types to an existing Stripe endpoint.

## 0.1.0 - 2026-07-23

Initial release.

### Added
- Session cart with `cart/add`, `cart/update`, `cart/remove`, and `cart/clear` actions.
- Stripe hosted Checkout handoff, built on the free [`craftcms/stripe`](https://plugins.craftcms.com/stripe) plugin.
- `craft stripe-cart/sync` command to pull the Stripe catalog into Craft.
- `craft stripe-cart/webhooks/subscribe` for one-time webhook setup, plus `status` and `unsubscribe`.
- Optional pricing tiers (for example retail and wholesale) with access-code and user-group activation.
- Pass-through of Stripe shipping rates via the `checkout.shippingOptions` setting.
- `craft.stripeCart` Twig variable, and `beforeCheckout`, `orderCompleted`, and `resolveTier` events.
