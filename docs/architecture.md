# Architecture

## In one paragraph

The plugin is built on the **Sylius 2.x PaymentRequest abstraction**
(`sylius/payment-bundle`) — the same architecture as the official Stripe/Adyen
plugins. **No Payum anywhere**: because no Payum factory named `everypay`
exists, Sylius' `PayumGatewayConfigTypeExtension` automatically stores
`use_payum = 0` on the gateway config, which routes the whole checkout through
the PaymentRequest pipeline. Every user-visible flow is a `PaymentRequest`
(action + state + payload/responseData bound to a Payment) dispatched as a
command on the synchronous `sylius.payment_request.command_bus`.

## The four flows

| Flow | Trigger | Chain |
|---|---|---|
| **Capture** | customer completes checkout → `GET /order/{token}/pay` → Sylius creates PR(action=capture) → redirect `/payment-request/pay/{hash}` | `EveryPayCommandProvider` → `CaptureEveryPayPaymentHandler`: first takes a **pessimistic row lock** on the PR (`$em->refresh($pr, PESSIMISTIC_WRITE)`, transaction supplied by the bus' `doctrine_transaction` middleware) — concurrent `/pay/{hash}` requests (double-click, browser retry) serialize instead of both creating an EveryPay payment for the same `order_reference` and the loser wrongly failing the payment. Then: builds the oneoff payload (`EveryPayOneOffPayloadFactory`), `POST /v4/payments/oneoff`, stores `payment_reference`+`payment_link` in `payment.details['everypay']` and PR responseData, PR → processing. Then `EveryPayHttpResponseProvider` redirects the customer to `payment_link` (303). API failure: PR + payment → failed, **no exception** — Sylius falls back to after-pay, the customer gets a failed flash and Sylius auto-creates a fresh `new` payment for retry. |
| **Status** (customer return) | EveryPay redirects to `customer_url` = `/order/after-pay/{hash}` → Sylius clones the PR as action=status | `StatusEveryPayPaymentHandler` → `EveryPayPaymentSynchronizer` (below). An API failure is swallowed (PR → failed): the payment stays processing, callbacks settle it later. |
| **Notify** (server callback) | EveryPay hits `/payment-methods/{code}?payment_reference=…&event_name=…` (static URL configured in the merchant portal) | Sylius `PaymentMethodNotifyAction` → `EveryPayNotifyPaymentProvider` resolves the Payment (`details LIKE '%<64-char hex ref>%'`, scoped to the method; malformed → 400, unknown → 404, both side-effect-free) → PR(action=notify) → `NotifyEveryPayPaymentHandler` → synchronizer. An API failure **propagates** → non-2xx response → EveryPay redelivers (6 retries / 72 h). Success → 204. |
| **Refund** (admin) | Core admin Refund button applies the `sylius_payment` `refund` transition → `workflow.sylius_payment.completed.refund` event | `RefundEveryPayPaymentListener` (guards: core payment, everypay factory, not synchronizer-initiated) wraps *create PR(refund) + announce* in an explicit DBAL transaction. `RefundEveryPayPaymentHandler` calls `POST /v4/payments/refund` (full amount). Failure → rollback + `UpdateHandlingException('everypay_refund_failed')` → the resource controller shows the error flash and **never flushes** — the DB keeps the payment `completed`. |

## EveryPayPaymentSynchronizer (the heart)

Both the customer return and the callback funnel here; it is idempotent and
treats the API as the single source of truth (callbacks and return query
params are unauthenticated hints, never trusted):

1. no `payment_reference` in details → capture never reached EveryPay → fail
   the payment (offers retry);
2. `GET /v4/payments/{reference}` → snapshot into `details['everypay']`;
3. `EveryPayStateMapper` maps the remote state to a target Sylius state;
   `getTransitionToState()` + a same-state check make duplicate deliveries
   no-ops. When the target state is unreachable from the current one (e.g. a
   payment stuck in `failed` while EveryPay reports `settled` — money moved),
   a `warning` is logged so an operator reconciles manually instead of the
   mismatch vanishing silently;
4. every transition is applied with the workflow context
   `EveryPayPaymentSynchronizer::WORKFLOW_CONTEXT` — this is how the refund
   listener distinguishes "admin clicked Refund" from "EveryPay told us it is
   refunded" (portal-initiated refunds must not trigger a second refund call).

## State mapping

| EveryPay | Sylius payment | Note |
|---|---|---|
| `initial` | — no-op | **deliberate**: the payment must stay `new`, because the shop's pay flow (`PaymentToPayResolver`) only re-enters for `new` payments — a customer who bounced off the hosted page can retry. Capture also does *not* move the payment to processing, for the same reason. |
| `waiting_for_3ds_response`, `waiting_for_sca`, `3ds_confirmed`, `sent_for_processing` | processing | |
| `settled` | completed | triggers order-paid and everything hooked to it (invoicing, emails) with zero extra wiring |
| `authorised` | authorized | only if manual capture is enabled on the processing account |
| `failed`, `abandoned` | failed | Sylius auto-creates the replacement payment |
| `voided` | cancelled | |
| `refunded` | refunded | with the sync workflow context (see above) |
| `charged_back` | — no-op + `warning` log | handled manually in the merchant portal; the raw state stays visible in payment details |

## File map

```
src/
├── PkgSyliusEveryPayPlugin.php             bundle class (SyliusPluginTrait)
├── DependencyInjection/                    loads config/services.php
├── EveryPayGateway.php                     factory name, config keys, base URLs,
│                                           details helpers (detailsFrom / paymentReferenceFrom)
├── Client/
│   ├── EveryPayApiClient.php               oneoff / status / refund; Basic auth, nonce,
│   │                                       ISO-8601 timestamp, error → EveryPayApiException
│   ├── EveryPayCredentials.php             DTO from (decrypted) gateway config; env → base URL
│   └── EveryPayApiException.php
├── Command/{Capture,Status,Notify,Refund}EveryPayPayment.php    hash-aware bus commands
├── CommandProvider/EveryPayCommandProvider.php                  action → command (one class)
├── CommandHandler/{Capture,Status,Notify,Refund}EveryPayPaymentHandler.php
├── Factory/EveryPayOneOffPayloadFactory.php   amount cents→decimal, order_reference
│                                              "{orderNumber}-{paymentId}" (unique per attempt),
│                                              locale mapping (lt_LT→lt, fallback en),
│                                              preferred_country EE/LV/LT, billing/shipping,
│                                              customer_ip (order → request fallback)
├── Fixture/EveryPayPaymentMethodExampleFactory.php  forces use_payum=false in fixtures
├── Processor/
│   ├── EveryPayStateMapper.php             pure state table (unit-tested)
│   └── EveryPayPaymentSynchronizer.php     API truth → state machine, idempotent
├── Provider/
│   ├── EveryPayHttpResponseProvider.php    redirect to payment_link (guards: capture action,
│   │                                       PR processing, payment new/processing)
│   └── EveryPayNotifyPaymentProvider.php   callback → Payment resolution
├── Form/EveryPayGatewayConfigurationType.php   admin config form (4 fields)
└── EventListener/RefundEveryPayPaymentListener.php  transactional refund bridge

config/services.php                         autowire/autoconfigure prototype over src/
config/config.yaml                          imports config/app/*.yaml (host app imports this)
config/app/sylius_payment.yaml              gateway validation groups
config/app/twig_hooks.yaml                  admin form hooks (required — see gotchas)
templates/admin/payment_method/...          gateway credential fields partial
translations/{messages,flashes}.{en,lt,et,lv}.yaml
tests/                                      unit tests (client, mapper, payload factory,
                                            synchronizer, command provider, capture handler)
```

## Wiring

All cross-cutting wiring lives in PHP attributes on the classes themselves;
`config/services.php` only registers the classes as autowired, autoconfigured
services:

- `#[AsGatewayConfigurationType(type: 'everypay', label: …)]` → admin gateway
  dropdown + config form (encrypted at rest in `sylius_gateway_config`,
  decrypted transparently on load)
- `#[AutoconfigureTag('sylius.payment_request.command_provider', ['gateway_factory' => 'everypay'])]`
- `#[AutoconfigureTag('sylius.payment_request.provider.http_response', ['gateway_factory' => 'everypay'])]`
- `#[AsNotifyPaymentProvider]` → callback payment resolution
- `#[AsMessageHandler]` on the four handlers (registered on all buses incl.
  `sylius.payment_request.command_bus`)
- `#[AsEventListener(event: 'workflow.sylius_payment.completed.refund')]`
- `#[AsDecorator('sylius.fixture.example_factory.payment_method', onInvalid: IGNORE)]`
- `#[Autowire(service: 'sylius_shop.provider.order_pay.after_pay_url')]`
  (customer_url), `sylius.factory.payment_request`,
  `sylius.repository.payment_request` (no autowiring aliases exist for those),
  `#[Autowire(param: 'sylius.model.payment.class')]` (notify DQL query)

`config/app/sylius_payment.yaml` carries
`sylius_payment.gateway_config.validation_groups.everypay: [sylius, everypay]`
(groups **replace** the default, so `sylius` must be repeated).

No DB migration: only core entities (`sylius_payment`,
`sylius_payment_request`, `sylius_gateway_config`) are used.

## Gotchas worth knowing

- **Admin gateway config fields render only via a per-factory Twig hook** —
  `sylius_admin.payment_method.{create,update}.content.form.sections.gateway_configuration.everypay`
  (shipped in `config/app/twig_hooks.yaml`). Without it the credential fields
  silently don't appear — the form type + attribute alone are not enough.
  The official Stripe/PayPal plugins ship the same kind of hook config.
- `api_secret` is a `PasswordType` (masked; a placeholder of dots hints that a
  value is stored); since a password widget never re-renders its value, a
  `PRE_SUBMIT` listener keeps the stored secret when the field is submitted
  empty ("leave blank to keep").
- **EveryPay rejects `customer_url` with a dotless host** — `http://localhost/...`
  fails oneoff validation, while `.localhost` subdomains pass (browsers resolve
  `*.localhost` to loopback natively).
- The capture handler intentionally leaves the payment in `new` (see the state
  mapping table) — do not "fix" this by moving it to `processing`.

## Partial refunds / `sylius/refund-plugin`

Deliberately out of scope for v1: the core admin Refund button + workflow
listener covers full refunds with zero extra dependencies. The adoption path
when partial refunds or credit memos become a real need:

- listen to `Sylius\RefundPlugin\Event\RefundPaymentGenerated` and call
  `EveryPayApiClient::refundPayment()` (it already accepts arbitrary partial
  amounts);
- **retire or guard `RefundEveryPayPaymentListener` at the same time** —
  RefundPlugin ultimately drives the payment to refunded too, and the workflow
  listener would double-fire the refund API call;
- note that RefundPlugin has a hard composer dependency on
  `knplabs/knp-snappy-bundle` (wkhtmltopdf); on hosts that cannot run binaries
  set `sylius_refund.pdf_generator.enabled: false` or swap in a DomPDF
  generator.

Also note the EveryPay-side caveat: for Open Banking payments the refund API
call **only marks the status refunded** (no money movement) unless the
merchant is an LHV customer — see [everypay-api.md](everypay-api.md#refunds).
