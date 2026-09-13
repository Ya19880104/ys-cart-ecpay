# YS CART - ECPay

Standalone ECPay provider plugin for YS CART.

Current version: **0.5.8**

## Features

- ECPay AIO redirect payment methods, declared in one place
  (`src/Payment/EcpayPaymentCatalog.php`) from which the manifest, gateway
  registration, settings keys and admin screen are all derived:
  - Credit Card, ATM, CVS Code, Barcode — usable under a plain ECPay contract
  - WebATM — usable under a plain contract, off by default because it is new
  - Credit instalments, UnionPay, Apple Pay, TWQR, WeChat Pay, BNPL — each
    requires activation with ECPay first, so each ships disabled with its
    prerequisite stated next to the switch. Upgrading never turns a method on.
  - Instalment periods are configurable and validated against the values ECPay
    documents (3, 5, 6, 8, 9, 10, 12, 18, 24, 30N). Leaving it empty hides the
    instalment method rather than sending an empty `CreditInstallment`, which
    ECPay would treat as an ordinary single payment.
  - BNPL carries ECPay's 3,000 TWD minimum, so it is hidden below that amount
    instead of failing at ECPay's payment page.
  - A method may declare extra AIO fields (UnionPay's `UnionPay=1`, instalments'
    `CreditInstallment`). They are merged before signing, so they are always
    inside CheckMacValue, and a field name colliding with an order field is
    refused rather than allowed to overwrite it.
- ECPay domestic logistics:
  - FamilyMart
  - 7-ELEVEN
  - Hi-Life
  - TCAT
  - Post
- HOME requests can explicitly use either the B2C/home or C2C credential profile,
  matching the capabilities enabled for that MerchantID in ECPay's merchant console.
- B2C/home and C2C independently use payment settings, separate settings, or no
  credentials. Shared settings follow payment test mode; separate settings keep
  their own test mode. Switching sources preserves the saved separate settings.
- The home-delivery (黑貓／郵局) source selector appears only when both the B2C/home
  and C2C groups are in use. If the selected group is set to "not used", it opens with
  a warning so the choice can be corrected; when hidden it is not submitted and the
  saved choice is unchanged.
- Administrators can change credentials after the on-page warning. Existing bound
  payments and pending payment/logistics requests may be affected. Permission,
  encryption, short writer exclusion, verified saving and rollback still apply.
- The API tab exposes ECPay's transaction modes and this plugin's support for each.
  Redirect (AIO) is the only implemented mode and stays the default: the card number
  never reaches this site, so there is no PCI-DSS burden, and it does not charge
  subscriptions automatically. ECPG Web (站內付 2.0) and AIO period contracts are
  shown with their prerequisites but cannot be selected; posting one is refused with
  `unsupported_payment_mode` rather than silently falling back. Background
  authorisation (card numbers sent from this server) requires PCI-DSS SAQ-D and is
  out of scope.
- The merchant check code (CreditCheckCode) is optional for normal payment setup.
  It is used by the advanced credit-card refund query, with the official location
  documented at https://developers.ecpay.com.tw/2894/.
- Shipping method visibility, sorting, base rates, and free-shipping rules are managed in YS CART Shipping Settings.
- ECPay CVS electronic map integration using YS CART's existing `cvs_store_id`, `cvs_store_name`, and `cvs_store_addr` checkout fields.
- **ECPay 站內付 2.0 bind-card credit card** (`ys_ec_ecpay_ecpg_credit`, 0.5.0) for
  contracted merchants: the card number is collected on a hosted page on the shop's
  own domain by ECPay's JS SDK and sent straight to ECPay (no PCI-DSS burden per
  ECPay). Subscription orders pay and bind in one authorisation (`CreateBindCard`);
  the returned BindCardID is stored encrypted in YS CART's card vault and later
  renewals charge it in the background (`CreatePaymentWithCardID`) through the same
  order-scoped token-charge contract PayUni uses. Non-subscription orders use the
  same page without binding. Ships disabled; requires ECPay activation of 站內付 2.0
  and 綁定信用卡. Coexists with the redirect (AIO) methods.
- YS Plugin Hub Client bundled for updates from yangsheep.com.tw.

## Requirements

- WordPress 6.2+
- PHP 8.2+
- PHP `mbstring` is recommended but not required; the provider includes a UTF-8-safe
  fallback for ECPay field-length limits.
- **YS CART 2.61.7+** (global AIO and logistics requirement; on top of the 2.56.12 set — typed
  fulfillment, durable logistics query, saved-address provider identity,
  encrypted-secret capability — the 2.58.0 pair contract additionally requires the shared
  `payment_detail` CAS service, stable payment operation keys, typed replay
  reservations, and deferred shipping pipeline hooks; 0.5.0 further requires the
  2.61.7 order-scoped token-charge contract used by ECPG bind-card renewals)
- **ECPG bind-card:** Core 2.66.3+ with the complete payment-effects receipt and
  initial-subscription card-binding APIs. When that method-level gate is not met,
  AIO payments and logistics remain available under the global 2.61.7 floor.
- **Multi-temperature mapping:** paired Core 2.67.0+. The provider exposes its
  11-method `temperature_layer_mapping_v1` capability only when that Core is loaded.

### Why YS CART 2.61.7 is a hard requirement

This plugin does not carry its own writer for the order `payment_detail` column.
It writes through the core's `YSPaymentDetailStore` compare-and-swap service and
relies on the core's `YSPaymentDispatch` operation keys so that every payment
attempt derives a stable transaction identity. Logistics callbacks also reserve
typed replay authority and defer the public pipeline hook until the provider's
payment-detail, order, and label projections are durable. The shared AIO and
logistics capability set is available from 2.61.7. The ECPG bind-card gateway is a
real token provider: it opts into `YSOrderScopedTokenChargeGatewayInterface`, binds
the chosen card identity through `YSPaymentDispatch`, and reads the stored BindCardID
through `YSCreditCard`'s token authority. Version 0.5.4 additionally requires Core
2.66.3's complete `YSPaymentEffects` receipt API and initial-subscription card binder
before ECPG is registered; the base plugin gate remains 2.61.7.

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
- One-time store result exchange: `/wp-json/ys-ecommerce-headless/v1/ecpay/store-result`
- Saved-store reauthorization: `/wp-json/ys-ecommerce-headless/v1/stores/ecpay/reauthorize`

Payment notify, payment info, return, store callback, and logistics notify are
provider-facing callback routes. Browser UI should request only the store-map
form route when the customer needs convenience-store selection.

## Headless Logistics

For ECPay CVS shipping, request the store-map form with the selected shipping
method ID **and the customer's currently selected payment method**:

```json
{
  "shipping_id": "ys_ec_ecpay_ship_unimart",
  "payment_method": "ys_ec_ecpay_credit"
}
```

`payment_method` is validated, not merely required — ECPay filters the store
list by cash-on-delivery mode, so an empty string or an unregistered gateway id
is *cannot prove*, not "assume no collection". Guests on another origin must
also identify themselves with the core `X-YS-Guest-Token` header; the token
issued for a store selection is bound to that owner.

Submit the returned `action_url` and `fields` as a top-level browser form post.
The callback redirects only to an allowlisted `return_url` with a 32-character
one-time result code. Exchange it through the result route under the same guest
or login identity; do not read WordPress-origin `localStorage` from a different
origin. The exchanged `selection_token` must be returned at checkout as
`ecpay_store_token` (v0.2.12); the store id alone is no longer accepted. Do not
expose ECPay hash keys or callback verification logic to browser code. See
`docs/headless.md`.

The bundled SDK exposes
`YsCartEcpay.setGuestToken(token)` and
`YsCartEcpay.requestStoreMapForm(apiBase, shippingId, paymentMethod, options)`,
then `resultCodeFromLocation()` + `claimStoreResult()` and the absolute-API
`checkout()` helper. Cookie-authenticated writes can set `X-WP-Nonce` through
`setWpNonce()`.

`cart_scope` is a canonical ABI: it must match `/^[a-z0-9_]{1,32}$/` exactly as
sent, or be omitted entirely — only an omitted scope falls back to `default`. The
server never normalises or downgrades it, so `HEADLESS_1`, `my-scope`, `''`,
`null`, an array, or anything longer than 32 characters returns HTTP 400 before
any principal is resolved, any map session is opened, or any one-time result code
is consumed. The two high-level SDK helpers — `requestStoreMapForm()` and
`claimStoreResult()` — enforce the same rule before sending and share one
validator, published as `YsCartEcpay.isCanonicalCartScope()`.

The remaining helpers post their payload as-is for ABI compatibility, and the
exact-400 promise above only covers the three ECPay public boundaries — no raw
helper enforces a destination by itself. `requestMapForm()` is a plain-POST
alias that accepts any caller-supplied URL; when the caller points it at the
ECPay map-url boundary, that destination fails closed with 400, and any other
destination follows its own rules. `checkout()` and `submitForm()` are
not scope-aware and follow their destination's own rules instead: `checkout()`
posts to the Core `/checkout/process` endpoint, whose parser normalises a
non-canonical `cart_scope` to `default` rather than rejecting it, and
`submitForm()` posts wherever the caller points it. A caller that needs the
strict behaviour on those paths must call `isCanonicalCartScope()` first. See
`docs/headless.md` for the full helper table.

## Release

```bash
php bin/build-release.php
```

The release zip root is `ys-cart-ecpay/` and excludes development-only files.
