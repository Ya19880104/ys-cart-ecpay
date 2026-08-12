# YS CART ECPay Headless Notes

The plugin uses the existing YS CART checkout process endpoint. When an ECPay payment method is selected, `/checkout/process` returns `form_data.action_url` plus hidden `fields`; the standard YS CART checkout client posts that form to ECPay.

## Identifying the shopper (required)

The store-selection token is bound to an owner. The server decides that owner
**when the map is opened** — a same-origin request that carries your cookies.

A headless front-end on a different origin has no YS CART cookie, so guests must
send the core guest-cart token instead:

```
X-YS-Guest-Token: <token issued by the core cart API>
```

The SDK does this for you once you call `YsCartEcpay.setGuestToken(token)`.
Without an identifiable shopper the map endpoint refuses to open — that is
deliberate: issuing a token whose owner is unknown means checkout would reject
it later, after the customer has already picked a store.

## Requesting the map form

`payment_method` is **required**. ECPay filters the store list by whether the
shipment collects payment on delivery, so the choice has to be known at
selection time. A missing or unknown value is not "assume no collection" — it is
*cannot prove*, and the endpoint rejects it:

| Sent | Result |
| --- | --- |
| omitted / `""` | `missing_payment_method` |
| a gateway id that is not registered | `unknown_payment_method` |
| `ys_ec_cod` on a method that cannot collect | `cod_not_supported_by_method` |
| `order_id` (any non-zero value) | `order_id_not_supported` |

```http
POST /wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url
Content-Type: application/json
X-YS-Guest-Token: 9f0c…

{
  "shipping_id": "ys_ec_ecpay_ship_unimart",
  "payment_method": "ys_ec_ecpay_credit"
}
```

The response contains:

```json
{
  "action_url": "https://logistics-stage.ecpay.com.tw/Express/map",
  "fields": {
    "MerchantID": "...",
    "MerchantTradeNo": "YSMAP…",
    "LogisticsType": "CVS",
    "LogisticsSubType": "UNIMART",
    "IsCollection": "N",
    "ServerReplyURL": "https://example.com/wp-json/ys-ecommerce/v1/ecpay/store-callback",
    "ExtraData": "…",
    "CheckMacValue": "…"
  },
  "temp_id": "…",
  "collection_mode": "N"
}
```

Submit the returned form in a popup or same window. On checkout context, the
callback stores `ys_ec_selected_store` in browser storage and redirects to
`/checkout/`. The stored payload includes `cvs_store_id`, `cvs_store_name`,
`cvs_store_addr` — and, since v0.2.12, `selection_token`.

Note that ECPay posts the callback from **its own servers**, so it carries none
of your cookies. The owner recorded in the token comes from the map request, not
from the callback; nothing in the callback payload identifies the shopper.

## Store selection token (v0.2.12, required at checkout)

The store id in the payload is just a string the browser can edit. The server
therefore keeps the authoritative record (owner, cart scope, shipping method,
subtype, store, collection mode, exact payment method) and hands the client an
opaque token.

**Checkout must send it back as `ecpay_store_token`.** Without it — or with a
token whose payment method, shipping method, store or cart scope no longer
match — checkout is rejected with a "please re-select the store" error.

The token is consumed **once, at the moment the order is created** — not during
field validation. So a checkout that fails on some *other* field (a malformed
phone number, a missing address) leaves the store selection intact: the customer
fixes the field, submits again, and keeps the store they picked. Two concurrent
checkouts still cannot share one selection; the claim is atomic.

The token expires with the map session (30 minutes).

```js
YsCartEcpay.setGuestToken(guestCartToken); // guests only; logged-in users skip this

const form = await YsCartEcpay.requestStoreMapForm(
  apiBase,
  'ys_ec_ecpay_ship_unimart',
  selectedPaymentMethod,          // required — do not call this before the customer picks one
  { cart_scope: 'default' }
);
YsCartEcpay.submitForm(form.data.action_url, form.data.fields, '_blank');

// …customer picks a store, callback writes ys_ec_selected_store…

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

## Surfaces the browser must not call

Use `shipping_id` as the public payload key. These routes are provider-facing
callback surfaces; they authenticate by ECPay's `CheckMacValue`, not by session,
and are not part of the client API:

| Route | Caller |
| --- | --- |
| `/wp-json/ys-ecommerce/v1/ecpay/store-callback` | ECPay map |
| `/wp-json/ys-ecommerce/v1/ecpay/logistics-notify` | ECPay logistics status |
| `/wp-json/ys-ecommerce/v1/ecpay/notify` | ECPay payment |
| `/wp-json/ys-ecommerce/v1/ecpay/return` | ECPay payment return |

## SDK surface

| Member | Purpose |
| --- | --- |
| `setGuestToken(token)` | Sets `X-YS-Guest-Token` on subsequent calls (guests) |
| `guestTokenHeader` | The header name, if you send requests yourself |
| `requestStoreMapForm(apiBase, shippingId, paymentMethod, options?)` | Map form; rejects locally when `paymentMethod` is empty |
| `selectionToken(selection)` | Reads `selection_token` out of the callback payload |
| `selectionTokenField` | `'ecpay_store_token'` — the checkout field name |
| `submitForm(actionUrl, fields, target?)` | Posts the returned form |
| `requestMapForm(url, payload)` | Lower-level compatibility helper only |
