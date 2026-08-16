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

**Send the same header on every cart and checkout call.** Since core 2.56.6 the
cart itself resolves its identity from this header when no cookie is present, so
a request without it lands on a *different, empty* cart than the one you built.

The token must be the 32-character value the core cart API issued; anything else
is ignored (it would otherwise let any string mint a cart).

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

Submit the returned form in the top-level window (or a popup whose final redirect
you control). ECPay returns a cross-site browser POST to the WordPress callback.
That callback keeps the selection on the server and redirects to the exact
allowlisted `return_url` with one query parameter:

```text
?ys_ec_store_result=<32-character one-time code>
```

The headless app must exchange that code through `/ecpay/store-result` using the
same guest/login principal that opened the map. The code is not the checkout
selection token, contains no store data, is single-use, and is sent with
`Cache-Control: no-store`. A wrong principal cannot read or consume it.

The cross-site callback normally carries no YS CART cookie because the guest
cookie is `SameSite=Lax`. The owner therefore comes from the same-origin map
request and is copied from the map session; the browser-carried callback does not
get to choose the owner.

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
YsCartEcpay.setGuestToken(guestCartToken); // guests: use this on map, result, cart and checkout
// Cookie-authenticated users instead provide the core REST nonce:
// YsCartEcpay.setWpNonce(wpRestNonce);

const form = await YsCartEcpay.requestStoreMapForm(
  apiBase,
  'ys_ec_ecpay_ship_unimart',
  selectedPaymentMethod,          // required — do not call this before the customer picks one
  {
    cart_scope: 'default',
    return_url: `${window.location.origin}/checkout/store-return`,
  }
);
YsCartEcpay.submitForm(form.data.action_url, form.data.fields, '_self');

// On /checkout/store-return, exchange the code exactly once.
const resultCode = YsCartEcpay.resultCodeFromLocation(window.location);
const result = await YsCartEcpay.claimStoreResult(apiBase, resultCode, {
  cart_scope: 'default',
});
const selection = result.data;

await YsCartEcpay.checkout(apiBase, {
    shipping_method: selection.shipping_id,
    payment_method:  selectedPaymentMethod,
    cvs_store_id:    selection.cvs_store_id,
    cvs_store_name:  selection.cvs_store_name,
    cvs_store_addr:  selection.cvs_store_addr,
    // v0.2.12: required
    [YsCartEcpay.selectionTokenField]: YsCartEcpay.selectionToken(selection),
});
```

`apiBase` must be the absolute YS CART API origin, for example
`https://shop-api.example.com`; `checkout()` appends
`/wp-json/ys-ecommerce-headless/v1/checkout/process` and never sends checkout to
a relative URL on the SPA origin. Requests use `credentials: 'include'`. Configure the core
headless CORS allowlist for the SPA origin; cookie-authenticated writes also need
`setWpNonce()`, while guests use the same 32-character guest token throughout.

If the customer changes the payment method after picking a store, open the map
again — the old token is bound to the previous payment method and will be
rejected.

## Surfaces the browser must not call

Use `shipping_id` as the public payload key. These routes are provider-facing
callback surfaces and are not client APIs. Payment/logistics callbacks verify
ECPay's `CheckMacValue`; the official map response has no signature and is bound
instead to the 20-character, single-use `ExtraData` map session (an optional but
invalid signature is still rejected):

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
| `setWpNonce(nonce)` | Sets `X-WP-Nonce` for cookie-authenticated writes |
| `guestTokenHeader` | The header name, if you send requests yourself |
| `requestStoreMapForm(apiBase, shippingId, paymentMethod, options?)` | Map form; rejects locally when `paymentMethod` is empty |
| `resultCodeFromLocation(location?)` | Reads `ys_ec_store_result` from the callback redirect URL |
| `claimStoreResult(apiBase, code, options?)` | Exchanges the one-time result code under the same principal |
| `checkout(apiBase, payload)` | Posts checkout to the absolute YS CART API origin with configured auth |
| `selectionToken(selection)` | Reads `selection_token` out of the callback payload |
| `selectionTokenField` | `'ecpay_store_token'` — the checkout field name |
| `submitForm(actionUrl, fields, target?)` | Posts the returned form |
| `requestMapForm(url, payload)` | Lower-level compatibility helper only |
