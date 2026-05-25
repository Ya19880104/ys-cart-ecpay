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

## Release

```bash
php bin/build-release.php
```

The release zip root is `ys-cart-ecpay/` and excludes development-only files.

