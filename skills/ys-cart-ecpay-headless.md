# YS CART ECPay Headless Skill

Use this when integrating ECPay with a headless YS CART checkout.

1. Let YS CART `/wp-json/ys-ecommerce-headless/v1/checkout/process` handle payment creation. Guests must send `X-YS-Guest-Token` on **every** cart and checkout call — since core 2.56.6 the cart resolves its identity from that header when there is no cookie, so omitting it checks out a different (empty) cart.
2. If the response contains `form_data.action_url`, submit the returned `fields` as a POST form to ECPay.
3. For ECPay CVS shipping, call `/stores/ecpay/map-url` with the selected shipping method ID as `shipping_id` **and the customer's currently selected `payment_method`** (required since v0.2.12 — ECPay filters stores by cash-on-delivery mode). The bundled SDK helper is `YsCartEcpay.requestStoreMapForm(apiBase, shippingId, paymentMethod, options)`.
4. Identify the shopper before opening the map. The token's owner is decided on the **map request** (a same-origin call that carries cookies), never on ECPay's callback — that callback is a cross-site POST and has none of your cookies. Guests on another origin must send the core `X-YS-Guest-Token`; call `YsCartEcpay.setGuestToken(token)` and the SDK adds it. With no identifiable shopper the endpoint refuses to open the map rather than issue a token checkout would later reject.
5. `payment_method` is validated, not just required: empty string, an unregistered gateway id, and `ys_ec_cod` on a method that cannot collect are all rejected (`missing_payment_method` / `unknown_payment_method` / `cod_not_supported_by_method`). The endpoint also rejects any non-zero `order_id`.
6. Return the map callback's `selection_token` to checkout as `ecpay_store_token` (`YsCartEcpay.selectionTokenField`). It is bound to the owner, cart scope, shipping method, subtype, store and exact payment method; changing the payment method after picking a store requires re-selecting.
7. The token is consumed **when the order is created**, not during field validation — a checkout that fails on some other field keeps the customer's store selection, so they can fix the field and resubmit. The claim is still atomic, so two concurrent checkouts cannot share one selection.
8. The map callback will write `ys_ec_selected_store` with `cvs_store_id`, `cvs_store_name`, and `cvs_store_addr`.
9. Send those three fields back with the checkout payload so YS CART stores them on the order.
10. Treat ECPay notify, return, store callback, and logistics notify routes as provider-facing callback surfaces, not browser UI APIs.
