# Release Notes for Stripe Cart

## Unreleased

### Added
- A zero-configuration, verified checkout return through Craft's action URL.
- A private fallback success template and the `successTemplate` site-template override.

### Changed
- The default post-payment URL now uses Craft's action URL. Sites that track the previous `/checkout/success` path can preserve it by routing that path to `stripe-cart/checkout/success` and setting `checkout.successUrl` explicitly.

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
