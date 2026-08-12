# YS CART ECPay Headless Skill

Use this when integrating ECPay with a headless YS CART checkout.

1. Let YS CART `/checkout/process` handle payment creation.
2. If the response contains `form_data.action_url`, submit the returned `fields` as a POST form to ECPay.
3. For ECPay CVS shipping, call `/stores/ecpay/map-url` with the selected shipping method ID as `shipping_id` **and the customer's currently selected `payment_method`** (required since v0.2.12 — ECPay filters stores by cash-on-delivery mode). The bundled SDK helper is `YsCartEcpay.requestStoreMapForm(apiBase, shippingId, paymentMethod, options)`.
4. Return the map callback's `selection_token` to checkout as `ecpay_store_token` (`YsCartEcpay.selectionTokenField`). It is single-use and bound to the owner, cart scope, shipping method, subtype, store and exact payment method; changing the payment method after picking a store requires re-selecting.
4. The map callback will write `ys_ec_selected_store` with `cvs_store_id`, `cvs_store_name`, and `cvs_store_addr`.
5. Send those three fields back with the checkout payload so YS CART stores them on the order.
6. Treat ECPay notify, return, store callback, and logistics notify routes as provider-facing callback surfaces, not browser UI APIs.
