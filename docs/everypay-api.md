# EveryPay API v4 - distilled integration reference

EveryPay (every-pay.com, Estonia) is the LHV Paytech e-commerce platform used
by the Baltic partner banks **SEB, LHV and Swedbank**. Not to be confused with
everypay.gr (a Greek company).

## Sources

- API reference (ReDoc): https://support.every-pay.com/apidoc/main/
- Help center: https://support.every-pay.com/en/ (collections: Custom
  Integration, Plugin Integration, SDKs)
- Merchant portal (live): https://portal.every-pay.eu/ - API username/secret
  under *Merchant settings -> General*
- EveryPay support: support@every-pay.com
- SEB e-commerce docs hub (test cards etc.): https://support.ecommerce.sebgroup.com/

## Environments

| | Base URL |
|---|---|
| Demo | `https://igw-demo.every-pay.com/api` |
| Production | `https://pay.every-pay.eu/api` |

Bank-branded demo portals (e.g. SEB's) share the demo backend: the same
credentials authenticate on both the generic and the bank-branded host, and
the `payment_link` returned by the API may point at the bank-branded skin -
the base URL above keeps working either way.

Authentication: **HTTP Basic** (`api_username` : `api_secret`). The
`api_username` (16 chars) must also be repeated inside every request body /
status query string.

## One-off payment flow (what this plugin uses)

1. `POST /v4/payments/oneoff` with a JSON body. Required:
   - `api_username`, `account_name` (processing account, e.g. `EUR3D1` -
     determines currency + available methods),
   - `amount` (decimal, 2 digits; Sylius stores cents -> divide by 100),
   - `order_reference` (unique per shop by default; multiple attempts allowed
     until one settles),
   - `nonce` (unique random string per request - replay protection),
   - `timestamp` (ISO 8601, must be within +/-5 min of EveryPay server time),
   - `customer_url` (absolute return URL; FQDN required - no dotless host, no IP).

   Useful optional: `locale` (`lt`, `en`, `lv`, `et`, `ru`, ...), `email`,
   `customer_ip`, `preferred_country` (`EE`/`LV`/`LT` - pre-selects the Open
   Banking country tab), `billing_*`/`shipping_*` address fields (improve card
   fraud scoring; card limits shrink without them from 2026-10),
   `payment_description` (Open Banking statement text, ~65 chars, charset
   `[a-zA-Z0-9/-?:().,'+]` - `order_reference` shares the same charset),
   `integration_details {integration, software, version}`.
2. Response `201`: `payment_reference` (64-char hex, the EveryPay payment id),
   `payment_link` (hosted payment page), `payment_state: initial`, `currency`,
   `payment_methods[]`.
3. Redirect the customer to `payment_link`. They pick card / bank link /
   wallet there (Apple Pay & Google Pay come free with the hosted page).
4. The customer returns to `customer_url` - EveryPay appends
   `?payment_reference=...&order_reference=...`. **Treat the return exactly like a
   callback: verify server-side, never trust query params.**
5. A server-to-server callback (see below) arrives in parallel - whichever
   comes first wins; both paths converge on the status query.
6. `GET /v4/payments/{payment_reference}?api_username=...` -> authoritative
   `payment_state`.

## Callback notifications

- The callback URL is **static, configured in the merchant portal** under
  *E-shop settings* (one per e-shop / processing account). For this plugin:
  `https://<shop-host>/payment-methods/<PAYMENT_METHOD_CODE>` (the Sylius
  notify endpoint).
- EveryPay calls it with query parameters: `payment_reference`,
  `order_reference` (deprecated - scheduled for removal, do not rely on it),
  `event_name`.
- `event_name` values: `status_updated`, `abandoned`, `voided`, `refunded`,
  `refund_failed`, `chargebacked`, `marked_for_capture`, fraud/dispute events,
  token events.
- **Callbacks are not signed/authenticated.** The only safe reaction is to
  re-query `GET /v4/payments/{payment_reference}` over authenticated TLS and
  act on that. This plugin does exactly that.
- Delivery retries: up to 6 attempts at 1 s, 5 min, 1 h, 24 h, 48 h, 72 h ->
  then permanent failure. The handler must be idempotent (the same state may
  be reported repeatedly).

## Payment states

| `payment_state` | Meaning | Plugin mapping |
|---|---|---|
| `initial` | created, method not chosen yet | no-op (payment stays retryable) |
| `waiting_for_3ds_response` / `waiting_for_sca` / `3ds_confirmed` | customer mid-authentication | processing |
| `sent_for_processing` | confirmed by customer, bank confirmation pending | processing |
| `settled` | **final success** (card settled / OB passed bank checks) | completed |
| `authorised` | card authorised, not yet captured (manual-capture accounts only) | authorized |
| `failed` | technical failure or issuer decline - final | failed |
| `abandoned` | customer walked away (15 min 3DS window) - final failure | failed |
| `voided` | authorisation cancelled - final | cancelled |
| `refunded` | fully/partially reimbursed | refunded |
| `charged_back` | cardholder dispute -> chargeback | left as-is; handled manually |

Default account setup is auto-capture -> success arrives directly as `settled`.

## Refunds

`POST /v4/payments/refund` - required: `api_username`, `payment_reference`,
`amount` (decimal, <= standing amount; partial allowed), `nonce`, `timestamp`.
Notes:

- Card payments: real money movement back to the customer.
- Open Banking: **only marks the status refunded** (no transaction) - unless
  the merchant is an LHV customer, where the OB refund is actually executed.
  For other banks, refund OB payments manually from the bank and use the API
  call to keep statuses in sync.

## Other endpoints (not used by this plugin, available)

`/v4/payments/cit|mit|charge` (token payments), `/v4/agreements`
(recurring/subscription), `/v4/tokens/*`, `/v4/payments/capture` + `/void`
(manual-capture accounts), `/v4/shops`, `/v4/processing_accounts`,
`/v4/mobile_payments/card_details` (in-app payments).

## Payment Elements (embedded checkout)

EveryPay also has an embedded web checkout - the **Payment Elements** JS SDK
its own platform plugins (WooCommerce 2.x, Magento, PrestaShop) mount in-page:
`{base host}/payment_elements/everypay-sdk-v1-0-0.umd.js` (UMD, global
`EveryPay`). This plugin implements it as the **experimental
`payment_elements` display mode**. No public documentation exists; the
de-facto contract below was extracted from EveryPay's own WooCommerce 2.0.4
plugin and the SDK bundle itself (build hash `74a259a625`, byte-identical on
the demo and production hosts as of 2026-07):

- Create a normal oneoff with the extra field **`mobile_payment: true`** -
  the response then carries a **`mobile_access_token`** (used by the SDK as
  a Bearer token for the Apple/Google Pay `payment_data` endpoints; card and
  bank flows work without it, but the SDK requires it in the intent).
- In the page: `new EveryPay({account, username})` -> `.secureElements({
  amount, locale, environment: 'demo'|'production', preferredCountry?,
  email?, stylingOptions: {theme: 'light', layout: 'tabs'|'accordion'}})`
  -> `.build({element: 'payment'})` -> `await element.mount('#selector')`.
  The element is an iframe of `{base host}/el/v3` (postMessage protocol,
  auto-resizing); it lists methods via the unauthenticated
  `GET /v4/sdk/payment_methods/{account_name}`.
- `element.submit()` validates/collects inside the iframe and resolves
  `{error: string|null}`. Card data never touches the shop page - EveryPay's
  [PCI DSS SAQ article](https://support.every-pay.com/en/articles/11163626-pci-dss-self-assessment-questionnaires)
  classifies Payment Elements as **SAQ A** (their "SDK(s) -> SAQ A-EP" row
  refers to the mobile app SDKs).
- `element.confirm({accountName, apiUsername, bearerToken, orderReference,
  paymentLink, returnURL, paymentReference})` finalizes and **always ends in
  a top-window redirect**: to `returnURL?payment_reference=...` (the normal
  customer return), into a 3DS challenge on the hosted page first, or - for
  bank methods - to `payment_link?method_source={bank}` exactly like the
  method grid. Nothing changes server-side: the return/callback/status flow
  stays authoritative.
- There is also a `managed` element variant (the iframe renders its own pay
  button, driven via an `onPaymentConfirmed` hook); no known production
  integration uses it, so this plugin sticks to `payment`.

Status 2026-07: **still no public integration documentation** - the help
center collections contain none, the bundle has changed under the same
`v1-0-0` URL within days, and `/el/v3` + `/v4/sdk/*` are undocumented.
support@every-pay.com has not yet confirmed the SDK for custom integrations -
which is why the display mode ships marked experimental, and why the hosted
page redirect fallback (no `mobile_access_token` -> redirect) must stay.

## Merchant portal setup checklist

1. Get demo credentials first; portal -> *Merchant settings -> General*: note
   `api_username`, `api_secret`.
2. Note the processing account name (e.g. `EUR3D1`) - it fixes the currency.
3. *E-shop settings*: set the callback URL (see above), keep "Additional
   notifications via callback" checked, and leave `order_reference` uniqueness
   validation **enabled** (default) - the plugin's `{orderNumber}-{paymentId}`
   format satisfies it.
4. In the Sylius admin: Payment methods -> Create -> gateway "EveryPay", fill in
   the credentials, pick the demo environment, enable for the channel.
5. Run a test payment (bank demo portals provide test cards / bank
   simulators; the 3DS challenge in demo is a simulator page - click **Yes**).
6. Onboarding: after a successful demo payment, report it to
   support@every-pay.com - they verify before issuing live credentials.
7. CDN/WAF (e.g. Cloudflare): ensure `/payment-methods/*` is not cached and
   not challenged (a bot challenge would eat the server-to-server callback).

## Local testing gotchas

- `customer_url` validation rejects dotless hosts (`localhost`) but accepts
  plain http and `.localhost` subdomains -> set the dev channel hostname to
  something like `myshop.localhost`.
- Callbacks: `ngrok http 80 --host-header=myshop.localhost` and point the
  portal callback URL at `https://<tunnel>/payment-methods/<code>`.
