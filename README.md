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
- **YS CART >= 2.57.0** (hard requirement)

### Why YS CART 2.57.0 is a hard requirement

This plugin does not carry its own writer for the order `payment_detail` column.
It writes through the core's `YSPaymentDetailStore` compare-and-swap service and
relies on the core's `YSPaymentDispatch` ambient guard so that every provider
write is owner-conditioned. Neither exists before 2.57.0.

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
The bundled SDK exposes `YsCartEcpay.requestStoreMapForm(apiBase, shippingId)`
for this request.

## Release

```bash
php bin/build-release.php
```

The release zip root is `ys-cart-ecpay/` and excludes development-only files.
