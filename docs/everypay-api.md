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

   Useful optional: `locale` (`lt`, `en`, `lv`, `et`, `ru`, ...), `email` and
   `phone_number {country_code, number}` (both flagged by the spec as "soon
   mandatory for all card payment requests" - upcoming Visa/Mastercard
   requirements; `country_code` is the dialling code, 1-4 digits, `number`
   4-14 digits - the plugin sends the billing phone, else the customer's,
   only when `EveryPayPhoneNumber` can split it safely), `customer_ip`, `preferred_country` (`EE`/`LV`/`LT` -
   pre-selects the Open Banking country tab), `billing_*`/`shipping_*` address
   fields (improve card fraud scoring; omitting them for card payments "may
   result in higher decline rates, degraded fraud screening, or limited
   dispute resolution"; stricter limits from 2027-01-04 - see
   [Address field rules](#address-field-rules-production-2027-01-04)),
   `payment_description` (Open Banking statement text, ~65
   chars, charset `[a-zA-Z0-9/-?:().,'+]` - `order_reference` shares the same
   charset and caps at 255 chars for cards / 120 for Open Banking),
   `structured_reference` (Open Banking structured payment reference,
   country-specific formats), `integration_details {integration, software,
   version}`.
2. Response `201`: `payment_reference` (64-char hex, the EveryPay payment id),
   `payment_link` (hosted payment page), `payment_state: initial`, `currency`,
   `payment_methods[]`.
3. Redirect the customer to `payment_link`. They pick card / bank link /
   wallet / PayPal there (Apple Pay & Google Pay come free with the hosted
   page).
4. The customer returns to `customer_url` - EveryPay appends
   `?payment_reference=...&order_reference=...`. **Treat the return exactly like a
   callback: verify server-side, never trust query params.**
5. A server-to-server callback (see below) arrives in parallel - whichever
   comes first wins; both paths converge on the status query.
6. `GET /v4/payments/{payment_reference}?api_username=...` -> authoritative
   `payment_state`. Optional `detailed=true` adds
   `detailed_fraud_check_results` (the portal's "Fraud Check Results" panel).

### Address field rules (production 2027-01-04)

Visa/Mastercard 3DS scheme rules, announced in EveryPay's merchant notice of
2026-09-21 (revised timeline; FAQ: help-centre article 16971870). From the
dates below, a request with a non-conforming value is **rejected** as an API
error - nothing is cut server-side.

| | Demo | Production |
|---|---|---|
| Country format | 2026-10-01 | 2027-01-04 |
| Address lines + length limits | 2026-11-01 | 2027-01-04 |

- `billing_country`/`shipping_country`: max 3 chars - ISO alpha-2 **stays
  valid**, ISO 3166-1 numeric (`233` for Estonia) is *additionally*
  accepted. The spec's "Format: ISO alpha-2 (3)" means exactly that.
- Address lines 1-3 are consolidated into one combined line, max 50 chars
  (characters, not bytes - diacritics count once). Join existing parts with
  ", "; if too long, keep the street name and house number and cut at a word
  boundary, never mid-word. The notice calls the field `billing_line` /
  `shipping_line`, but no published spec (EveryPay's or SEB's, both dated
  2026-07-14) has that name yet - `billing_line1` is still the documented
  field. Verify on demo from 2026-11-01.
- `billing_city`/`shipping_city` max 50, `*_postcode` max 16,
  `billing_state` ISO 3166-2.
- Address fields stay optional, but send at least the billing address for
  card payments. Omit the shipping fields entirely when nothing ships
  (digital goods, services) - no placeholder data.

The plugin sends Sylius' single street field as `*_line1`, shortens city and
street at a word boundary, drops a postcode/state/country that does not fit
rather than cutting a code, and sends no shipping fields when the order does
not require shipping.

## Callback notifications

- The callback URL is **static, configured in the merchant portal** under
  *E-shop settings* (one per e-shop / processing account). For this plugin:
  `https://<shop-host>/payment-methods/<PAYMENT_METHOD_CODE>` (the Sylius
  notify endpoint).
- EveryPay calls it with query parameters: `payment_reference`,
  `order_reference` (deprecated - scheduled for removal, do not rely on it),
  `event_name`.
- `event_name` values: `status_updated`, `abandoned`, `voided`, `refunded`,
  `refund_failed`, `chargebacked`, `marked_for_capture`,
  `issuer_reported_fraudulent`, `merchant_reported_fraudulent`,
  `dispute_opened` / `dispute_updated` / `dispute_reversed` /
  `dispute_charged_back` / `dispute_reopened`, `card_art_updated`,
  `token_updated`, and - easy to confuse with `status_updated` -
  `status_update`: acquiring completed, funds received on the merchant
  account (pairs with the `acquiring_completed_at` status field; only sent
  when the acquiring bank supports it).
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

Payer-visible statement texts (not in the API docs; confirmed 2026-08 on
both the merchant's and the payer's statements - both carry the same texts):

- OB payment: the description line is `payment_description`; the
  counterparty is the merchant name transliterated to the SEPA restricted
  charset by the payment-initiation rails (uppercased, diacritics and
  quotation marks reduced).
- LHV-executed OB refund: the description is the original
  `payment_description` prefixed with `Refund - `. The refund API has no
  text field - the text is composed on the EveryPay/LHV side and is not
  integrator-controllable. This record's counterparty is the account-holder
  name as registered at LHV (full diacritics), so the payer can see the
  same merchant under two spellings across the pair.
- Card payments/refunds never carry `payment_description`; card statements
  show the shop `descriptor` (+ MCC) set at onboarding (see `/v4/shops`).

## Other endpoints (not used by this plugin, available)

`/v4/payments/cit|mit|charge` (token payments), `/v4/agreements`
(recurring/subscription), `/v4/tokens/*`, `/v4/payments/capture` + `/void`
(manual-capture accounts), `/v4/shops`, `/v4/processing_accounts`,
`/v4/mobile_payments/card_details` (in-app payments).

Token-related oneoff extras: `request_token` + `token_agreement`
(`unscheduled`/`recurring` - the agreement of a follow-up CIT/MIT must match
the one used at tokenization), `token_consent_agreed` (merchant carries the
token consent in its own T&C - hides the save-card checkbox on the hosted
page), `amount: 0` saves a card without any purchase.

## Payment Elements (embedded checkout)

EveryPay's embedded web checkout is the **Payment Elements** JS SDK -
`{base host}/payment_elements/everypay-sdk-v1-0-0.umd.js` (UMD, global
`EveryPay`). EveryPay documents it for custom integrations in a merchant
integration guide attached to the help-centre article
[Payment Elements](https://support.every-pay.com/en/articles/16626823-payment-elements)
(published 2026-09-10). This plugin implements it as the **experimental
`payment_elements` display mode**. The contract it relies on:

- Create a normal oneoff with **`mobile_payment: true`** - without it the
  response carries no **`mobile_access_token`**, which `confirm()` needs as
  its `bearerToken`.
- In the page: `new EveryPay({account, username})` -> `.secureElements({
  stylingOptions: {theme: 'light', layout: 'tabs'|'accordion'}, amount,
  locale, environment: 'demo'|'production', preferredCountry?, email?,
  phoneNumber?: {countryCode, phoneNumber}, allowedPaymentMethods?,
  tokenization?})` -> `.build({element: 'payment'})` ->
  `await element.mount('#selector')`. Omit an unknown optional field
  entirely rather than passing an empty value. The element is an iframe of
  `{base host}/el/v3`.
- `element.submit()` validates inside the iframe. It **rejects** for most
  invalid input (card number, name, CVC, expiry) but **resolves with
  `{error}`** when no method is selected - handle both.
- `element.confirm({accountName, apiUsername, bearerToken, orderReference,
  paymentLink, returnURL, paymentReference})` finalizes. Card, bank and
  PayPal leave the page from within `confirm()` - to `returnURL`, into a 3DS
  challenge, or to the bank - and it never returns. **Apple Pay and Google
  Pay complete in the wallet sheet and `confirm()` resolves**, so the page
  has to go to the return URL itself.
- `element.paymentMethod` emits `change` events (`card` | `bank` |
  `apple_pay` | `google_pay` | `paypal` | `null`);
  `element.selectedPaymentMethod()` reads the current one.
- Styling: CSS custom properties, read only from the mount container's
  inline `style` attribute (not from stylesheets).
- Card data never touches the shop page - EveryPay's
  [PCI DSS SAQ article](https://support.every-pay.com/en/articles/11163626-pci-dss-self-assessment-questionnaires)
  classifies Payment Elements as **SAQ A**.
- **The `api_username` and processing account name are public in this
  mode**: the SDK takes them in the browser by design, and only `api_secret`
  must stay server-side. The redirect and method grid modes never send
  either to the browser.
- Wallets need HTTPS. Google Pay also needs its terms accepted in the
  merchant portal (E-shop settings), and Apple Pay needs the portal's
  domain-verification file served under `/.well-known/` and the domain
  registered there.
- Nothing changes server-side: the return/callback/status flow stays
  authoritative.

The bundle changes under the same `v1-0-0` URL without notice (last seen
changing 2026-10-05, byte-identical on the demo and production hosts). The
mode stays experimental - with the hosted page redirect fallback whenever
there is no `mobile_access_token` or the SDK fails to load - until it has
run in production.

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
