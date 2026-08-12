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

Payment notify, payment info, return, store callback, and logistics notify are
provider-facing callback routes. Browser UI should request only the store-map
form route when the customer needs convenience-store selection.

## Headless Logistics

For ECPay CVS shipping, request the store-map form with the selected shipping
method ID:

```json
{
  "shipping_id": "ys_ec_ecpay_ship_unimart"
}
```

Submit the returned `action_url` and `fields` as a top-level browser form post
or popup form post. Do not expose ECPay hash keys or callback verification logic
to browser code.
Checkout must return the map callback's `selection_token` as `ecpay_store_token`
(v0.2.12); the store id alone is no longer accepted. See `docs/headless.md`.

The bundled SDK exposes
`YsCartEcpay.requestStoreMapForm(apiBase, shippingId, paymentMethod, options)`
for this request.

## Release

```bash
php bin/build-release.php
```

The release zip root is `ys-cart-ecpay/` and excludes development-only files.
