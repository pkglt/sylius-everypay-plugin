# AGENTS.md — instructions for AI coding agents

This file is the canonical agent guide for this repository. `CLAUDE.md`
(Claude Code) points here; agents for other tools should read this file too.

## What this project is

A **Sylius 2.x payment gateway plugin** for [EveryPay](https://every-pay.com)
(LHV Paytech — the platform behind SEB/LHV/Swedbank e-commerce payments in the
Baltics). Composer package `pkglt/sylius-everypay-plugin`, PSR-4 namespace
`Pkg\SyliusEveryPayPlugin\` (tests: `Tests\Pkg\SyliusEveryPayPlugin\`).

It is built on the Sylius **PaymentRequest** abstraction
(`sylius/payment-bundle`) — the modern, non-Payum pipeline also used by the
official Stripe plugin ([Sylius/StripePlugin](https://github.com/Sylius/StripePlugin),
the structural reference for this repo). There is **no Payum code anywhere**,
and that is a design decision, not an omission.

Read before non-trivial changes:

- `docs/architecture.md` — the four flows, state mapping, wiring, gotchas
- `docs/everypay-api.md` — distilled EveryPay API v4 reference

## Commands

```bash
composer install
vendor/bin/phpunit             # unit tests (pure, MockHttpClient — no DB, no kernel)
vendor/bin/phpstan analyse     # level 9, src/ + tests/
vendor/bin/ecs check           # Sylius Labs coding standard (--fix to autofix)
```

All three must pass before a change is done. There is no test application or
Behat suite yet (roadmap) — functional verification happens inside a host
Sylius app.

## Architecture in 30 seconds

Four flows, all driven by `PaymentRequest` commands on the synchronous
`sylius.payment_request.command_bus`:

- **capture** — create EveryPay one-off payment, redirect customer to the
  hosted `payment_link`
- **status** — customer returned; re-read state from the EveryPay API
- **notify** — server callback; resolve payment by `payment_reference`,
  re-read state from the API
- **refund** — admin pressed Refund; call the refund API inside a transaction

`Processor/EveryPayPaymentSynchronizer` is the heart: status + notify funnel
into it; it is idempotent and treats `GET /v4/payments/{ref}` as the single
source of truth.

## Invariants — do not break these

1. **Never trust callbacks or return-URL query params.** They are
   unauthenticated hints. Every state change must come from an authenticated
   `GET /v4/payments/{payment_reference}` call.
2. **A payment stays `new` until EveryPay reports an in-flight/final state.**
   Capture deliberately does not move the payment to `processing`, and the
   `initial` state maps to no-op — Sylius' `PaymentToPayResolver` only
   re-enters the pay flow for `new` payments, and customers who bounce off the
   hosted page must be able to retry. Do not "fix" this.
3. **The synchronizer's workflow context guard**
   (`EveryPayPaymentSynchronizer::WORKFLOW_CONTEXT`, value `pkg_everypay_sync`)
   is what prevents a portal-initiated refund callback from triggering a
   second refund API call via `RefundEveryPayPaymentListener`. Any new
   listener on payment transitions must respect it.
4. **The refund listener's explicit DBAL transaction + rethrow as
   `UpdateHandlingException`** is what keeps a payment `completed` in the DB
   when the EveryPay refund API fails. Don't replace it with a plain flush.
5. **The capture handler's pessimistic lock**
   (`$em->refresh($pr, PESSIMISTIC_WRITE)`) serializes concurrent
   `/pay/{hash}` requests. It relies on the bus'`doctrine_transaction`
   middleware providing the transaction.
6. **Handlers must stay idempotent** — callbacks are redelivered and the
   customer return races them.

## Wiring conventions

- All service wiring is **PHP attributes on the classes**
  (`#[AsMessageHandler]`, `#[AsEventListener]`, `#[AsGatewayConfigurationType]`,
  `#[AsNotifyPaymentProvider]`, `#[AsDecorator]`, `#[AutoconfigureTag]`,
  `#[Autowire]`). `config/services.php` is only an autowire/autoconfigure
  prototype with exclusions (Command DTOs, value objects, gateway constants).
  New services: put the attribute on the class; only touch `services.php` if
  the class must be excluded or needs non-attribute wiring.
- App-level Sylius config the host application must load lives in
  `config/app/*.yaml` (imported via `config/config.yaml`, Stripe-plugin
  convention): validation groups and the admin form Twig hooks.
- **Gateway config fields render only through the
  `gateway_configuration.everypay` Twig hook** (`config/app/twig_hooks.yaml`
  → `templates/admin/payment_method/...`). The form type alone is not enough;
  if fields "silently disappear", look there first.
- Validation groups **replace** the Sylius default — `sylius` must always be
  listed alongside `everypay` in `config/app/sylius_payment.yaml`.
- `EveryPayGateway` holds all shared constants (factory name, config keys,
  base URLs, payment-details helpers). Don't scatter string literals.

## Conventions

- PHP 8.2+, `declare(strict_types=1)`, `final` classes, constructor property
  promotion, readonly where possible.
- Coding standard: Sylius Labs ECS; static analysis phpstan **level 9** —
  keep both green, no baseline files.
- Translation domains: admin UI keys under `pkg_everypay.ui.*` in
  `translations/messages.<locale>.yaml`; the refund-failure flash lives under
  `sylius.payment.everypay_refund_failed` in `flashes.<locale>.yaml` (the key
  prefix is dictated by Sylius' flash rendering — do not move it).
  Locales: en, lt, et, lv — keep all four in sync when adding keys.
- Tests are pure unit tests using Symfony `MockHttpClient` and hand-rolled
  fakes — no kernel boots, no DB. Follow the existing test style
  (data providers, explicit transition assertions).
- Commit messages: imperative mood, plain sentences, no conventional-commit
  prefixes, no AI co-author trailers.

## Gotchas

- `api_secret` is a `PasswordType`; a `PRE_SUBMIT` listener restores the
  stored secret when the field is submitted empty ("leave blank to keep").
  A password widget never re-renders its value — that's why.
- EveryPay rejects `customer_url` with a dotless host (`localhost` fails,
  `myshop.localhost` passes).
- `Fixture/EveryPayPaymentMethodExampleFactory` decorates the payment-method
  example factory to force `use_payum = false` (the fixture tree has no
  usePayum node and defaults to true, which would route checkout into Payum
  where no `everypay` factory exists). It self-removes when the fixture
  service is absent (`onInvalid: IGNORE_ON_INVALID_REFERENCE`).
- `EveryPayNotifyPaymentProvider` resolves payments with a DQL `LIKE` over the
  JSON details column, using the `sylius.model.payment.class` parameter — it
  must keep working with app-overridden Payment entities.

## Roadmap (see README)

Partial refunds via `sylius/refund-plugin` (adoption path documented in
`docs/architecture.md` — the workflow listener must be guarded when adopted),
tokenized/CIT payments, per-method direct payment links, Behat/functional
coverage against a mocked EveryPay API.
