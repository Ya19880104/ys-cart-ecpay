# YS CART ECPay Headless Notes

The plugin uses the existing YS CART checkout process endpoint. When an ECPay payment method is selected, `/checkout/process` returns `form_data.action_url` plus hidden `fields`; the standard YS CART checkout client posts that form to ECPay.

For CVS shipping methods, request an ECPay map form:

```http
POST /wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url
Content-Type: application/json

{
  "shipping_id": "ys_ec_ecpay_ship_unimart"
}
```

The response contains:

```json
{
  "action_url": "https://logistics-stage.ecpay.com.tw/Express/map",
  "fields": {
    "MerchantID": "...",
    "LogisticsType": "CVS",
    "LogisticsSubType": "UNIMART"
  }
}
```

Submit the returned form in a popup or same window. On checkout context, the callback stores `ys_ec_selected_store` in browser storage and redirects to `/checkout/`. The stored payload includes `cvs_store_id`, `cvs_store_name`, and `cvs_store_addr`.

