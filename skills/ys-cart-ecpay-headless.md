# YS CART ECPay Headless Skill

Use this when integrating ECPay with a headless YS CART checkout.

1. Let YS CART `/checkout/process` handle payment creation.
2. If the response contains `form_data.action_url`, submit the returned `fields` as a POST form to ECPay.
3. For ECPay CVS shipping, call `/stores/ecpay/map-url` with the selected shipping method ID.
4. The map callback will write `ys_ec_selected_store` with `cvs_store_id`, `cvs_store_name`, and `cvs_store_addr`.
5. Send those three fields back with the checkout payload so YS CART stores them on the order.

