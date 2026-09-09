# YS CART - ECPay

Standalone ECPay provider plugin for YS CART.

## Features

- ECPay AIO payment gateways:
  - Credit Card
  - ATM
  - CVS Code
  - Barcode
- ECPay domestic logistics:
  - FamilyMart
  - 7-ELEVEN
  - Hi-Life
  - TCAT
  - Post
- HOME requests can explicitly use either the B2C/home or C2C credential profile,
  matching the capabilities enabled for that MerchantID in ECPay's merchant console.
- B2C/home and C2C independently use payment settings, separate settings, or no
  credentials. Shared settings follow payment test mode; separate settings keep
  their own test mode. Switching sources preserves the saved separate settings.
- Administrators can change credentials after the on-page warning. Existing bound
  payments and pending payment/logistics requests may be affected. Permission,
  encryption, short writer exclusion, verified saving and rollback still apply.
- The merchant check code (CreditCheckCode) is optional for normal payment setup.
  It is used by the advanced credit-card refund query, with the official location
  documented at https://developers.ecpay.com.tw/2894/.
- Shipping method visibility, sorting, base rates, and free-shipping rules are managed in YS CART Shipping Settings.
- ECPay CVS electronic map integration using YS CART's existing `cvs_store_id`, `cvs_store_name`, and `cvs_store_addr` checkout fields.
- YS Plugin Hub Client bundled for updates from yangsheep.com.tw.

## Requirements

- WordPress 6.2+
- PHP 8.1+
- PHP `mbstring` is recommended but not required; the provider includes a UTF-8-safe
  fallback for ECPay field-length limits.
- **YS CART 2.58.0+** (hard requirement; on top of the 2.56.12 set — typed
  fulfillment, durable logistics query, saved-address provider identity,
  encrypted-secret capability — the 2.58.0 pair contract additionally requires the shared
  `payment_detail` CAS service, stable payment operation keys, typed replay
  reservations, and deferred shipping pipeline hooks)

### Why YS CART 2.58.0 is a hard requirement

This plugin does not carry its own writer for the order `payment_detail` column.
It writes through the core's `YSPaymentDetailStore` compare-and-swap service and
relies on the core's `YSPaymentDispatch` operation keys so that every payment
attempt derives a stable transaction identity. Logistics callbacks also reserve
typed replay authority and defer the public pipeline hook until the provider's
payment-detail, order, and label projections are durable. The complete capability
set is available from 2.58.0.

If the core is older, the plugin **registers no payment gateways and no shipping
methods** and shows an admin notice instead. A provider that is registered but
cannot persist safely is more dangerous than one that is visibly absent — the
first one takes money.

## Callback Routes

- Payment notify: `/wp-json/ys-ecommerce/v1/ecpay/notify`
- Payment info: `/wp-json/ys-ecommerce/v1/ecpay/payment-info`
- Browser return: `/wp-json/ys-ecommerce/v1/ecpay/return`
- Store callback: `/wp-json/ys-ecommerce/v1/ecpay/store-callback`
- Logistics notify: `/wp-json/ys-ecommerce/v1/ecpay/logistics-notify`
- Store map form: `/wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url`
- One-time store result exchange: `/wp-json/ys-ecommerce-headless/v1/ecpay/store-result`
- Saved-store reauthorization: `/wp-json/ys-ecommerce-headless/v1/stores/ecpay/reauthorize`

Payment notify, payment info, return, store callback, and logistics notify are
provider-facing callback routes. Browser UI should request only the store-map
form route when the customer needs convenience-store selection.

## Headless Logistics

For ECPay CVS shipping, request the store-map form with the selected shipping
method ID **and the customer's currently selected payment method**:

```json
{
  "shipping_id": "ys_ec_ecpay_ship_unimart",
  "payment_method": "ys_ec_ecpay_credit"
}
```

`payment_method` is validated, not merely required — ECPay filters the store
list by cash-on-delivery mode, so an empty string or an unregistered gateway id
is *cannot prove*, not "assume no collection". Guests on another origin must
also identify themselves with the core `X-YS-Guest-Token` header; the token
issued for a store selection is bound to that owner.

Submit the returned `action_url` and `fields` as a top-level browser form post.
The callback redirects only to an allowlisted `return_url` with a 32-character
one-time result code. Exchange it through the result route under the same guest
or login identity; do not read WordPress-origin `localStorage` from a different
origin. The exchanged `selection_token` must be returned at checkout as
`ecpay_store_token` (v0.2.12); the store id alone is no longer accepted. Do not
expose ECPay hash keys or callback verification logic to browser code. See
`docs/headless.md`.

The bundled SDK exposes
`YsCartEcpay.setGuestToken(token)` and
`YsCartEcpay.requestStoreMapForm(apiBase, shippingId, paymentMethod, options)`,
then `resultCodeFromLocation()` + `claimStoreResult()` and the absolute-API
`checkout()` helper. Cookie-authenticated writes can set `X-WP-Nonce` through
`setWpNonce()`.

`cart_scope` is a canonical ABI: it must match `/^[a-z0-9_]{1,32}$/` exactly as
sent, or be omitted entirely — only an omitted scope falls back to `default`. The
server never normalises or downgrades it, so `HEADLESS_1`, `my-scope`, `''`,
`null`, an array, or anything longer than 32 characters returns HTTP 400 before
any principal is resolved, any map session is opened, or any one-time result code
is consumed. The two high-level SDK helpers — `requestStoreMapForm()` and
`claimStoreResult()` — enforce the same rule before sending and share one
validator, published as `YsCartEcpay.isCanonicalCartScope()`.

The remaining helpers post their payload as-is for ABI compatibility, and the
exact-400 promise above only covers the three ECPay public boundaries — no raw
helper enforces a destination by itself. `requestMapForm()` is a plain-POST
alias that accepts any caller-supplied URL; when the caller points it at the
ECPay map-url boundary, that destination fails closed with 400, and any other
destination follows its own rules. `checkout()` and `submitForm()` are
not scope-aware and follow their destination's own rules instead: `checkout()`
posts to the Core `/checkout/process` endpoint, whose parser normalises a
non-canonical `cart_scope` to `default` rather than rejecting it, and
`submitForm()` posts wherever the caller points it. A caller that needs the
strict behaviour on those paths must call `isCanonicalCartScope()` first. See
`docs/headless.md` for the full helper table.

## Release

```bash
php bin/build-release.php
```

The release zip root is `ys-cart-ecpay/` and excludes development-only files.
