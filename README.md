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
- Shipping method visibility, sorting, base rates, and free-shipping rules are managed in YS CART Shipping Settings.
- ECPay CVS electronic map integration using YS CART's existing `cvs_store_id`, `cvs_store_name`, and `cvs_store_addr` checkout fields.
- YS Plugin Hub Client bundled for updates from yangsheep.com.tw.

## Requirements

- WordPress 6.2+
- PHP 8.1+
- YS CART with provider hook support

## Callback Routes

- Payment notify: `/wp-json/ys-ecommerce/v1/ecpay/notify`
- Payment info: `/wp-json/ys-ecommerce/v1/ecpay/payment-info`
- Browser return: `/wp-json/ys-ecommerce/v1/ecpay/return`
- Store callback: `/wp-json/ys-ecommerce/v1/ecpay/store-callback`
- Logistics notify: `/wp-json/ys-ecommerce/v1/ecpay/logistics-notify`
- Store map form: `/wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url`
- One-time store result exchange: `/wp-json/ys-ecommerce-headless/v1/ecpay/store-result`

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

## Release

```bash
php bin/build-release.php
```

The release zip root is `ys-cart-ecpay/` and excludes development-only files.
