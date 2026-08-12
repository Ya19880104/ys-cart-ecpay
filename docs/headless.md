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

Submit the returned form in a popup or same window. On checkout context, the
callback stores `ys_ec_selected_store` in browser storage and redirects to
`/checkout/`. The stored payload includes `cvs_store_id`, `cvs_store_name`,
`cvs_store_addr` — and, since v0.2.12, `selection_token`.

## Store selection token (v0.2.12, required at checkout)

The store id in the payload is just a string the browser can edit. The server
therefore keeps the authoritative record (owner, cart scope, shipping method,
subtype, store, collection mode, exact payment method) and hands the client an
opaque token.

**Checkout must send it back as `ecpay_store_token`.** Without it — or with a
token whose payment method, shipping method, store or cart scope no longer
match — checkout is rejected with a "please re-select the store" error. The
token is single-use and expires with the map session (30 minutes).

```js
const selection = JSON.parse(localStorage.getItem('ys_ec_selected_store'));

await fetch('/wp-json/ys-ecommerce/v1/checkout/process', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    shipping_method: selection.shipping_id,
    payment_method:  selectedPaymentMethod,
    cvs_store_id:    selection.cvs_store_id,
    cvs_store_name:  selection.cvs_store_name,
    cvs_store_addr:  selection.cvs_store_addr,
    // v0.2.12: required
    [YsCartEcpay.selectionTokenField]: YsCartEcpay.selectionToken(selection),
  }),
});
```

If the customer changes the payment method after picking a store, open the map
again — the old token is bound to the previous payment method and will be
rejected.

Use `shipping_id` as the public payload key. ECPay notify, return, store
callback, and logistics notify routes are provider-facing callback surfaces and
should not be called directly by browser UI.
The bundled SDK exposes `YsCartEcpay.requestStoreMapForm(apiBase, shippingId)`
for this request and keeps `requestMapForm(url, payload)` only as a lower-level
compatibility helper.
