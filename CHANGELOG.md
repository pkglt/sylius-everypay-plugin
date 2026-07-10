# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The admin order page shows EveryPay data on each payment row: the raw
  EveryPay state, the payment reference, and (for live payments) a link
  to the merchant portal.
- Gateway log entries go to a dedicated `everypay` Monolog channel, so
  operators can route or filter them independently.

### Changed

- Documentation, code comments and UI texts use plain ASCII punctuation.

## [0.3.1] - 2026-07-08

### Added

- Billing and shipping province is sent to EveryPay as `billing_state` /
  `shipping_state`, when the address carries a province code.

### Changed

- `integration_details` now identifies this plugin: `integration` reports
  `pkglt/sylius-everypay-plugin` and `version` reports the installed package
  version (resolved via `Composer\InstalledVersions`), replacing the previous
  `custom` / hardcoded `2.2` placeholder. This adds a `composer-runtime-api`
  requirement, satisfied by every Composer 2 install.

### Fixed

- The 3DS mid-authentication state is recognized by its real EveryPay name
  `waiting_for_3ds_response` (mapped to `processing`); the placeholder
  `waiting_for_3ds` entry was removed.

## [0.3.0] - 2026-07-06

### Changed

- The in-shop payment method page now reads as part of the checkout: order
  number, amount due, a "you will return to the shop" note and a back-to-order
  link; methods are grouped by country (locale-aware country names), with the
  customer's billing country first, international methods (card, Revolut)
  second, and the remaining countries after - instead of one flat list where
  every Baltic bank appeared three times.
- Template contract: `method_groups` + `payment` replace the flat
  `payment_methods` variable - re-check any template override.

## [0.2.2] - 2026-07-06

### Fixed

- The API secret field no longer triggers the browser's "suggest strong
  password" generator: the `autocomplete` hint moved from `new-password` to
  `one-time-code` - the secret is issued by EveryPay and pasted in, never
  created. Autofill and save-password prompts stay suppressed.

## [0.2.1] - 2026-07-06

### Fixed

- Browsers no longer offer to save or autofill the API credential fields
  (`autocomplete` hints on the username/secret pair) - an autofilled secret
  submitted as non-empty and silently overwrote the stored one.

## [0.2.0] - 2026-07-06

### Added

- Optional in-shop payment method grid: with "Checkout appearance" set to
  the method buttons, customers pick their bank/card inside the store (with
  EveryPay's method logos) and land directly on the chosen payment page;
  methods without a per-method link fall back to the hosted page.
- Gateway credentials are verified against the EveryPay API when the admin
  saves the payment method - definitive rejections (bad secret, unknown
  processing account) fail validation, an unreachable EveryPay never blocks
  saving.
- Behat suite (11 scenarios) on the same test application and scripted
  EveryPay mock as the functional suite: the shop payment lifecycle
  (redirect to the hosted page, settled return, callback settle when the
  customer never returns, failure with automatic retry payment, pay-page
  reload and duplicate-callback idempotency), refunds (admin refund calls
  EveryPay exactly once, a refund made in the EveryPay portal is never
  refunded twice, a failed refund leaves the payment completed) and admin
  credential management (fields render through the twig hook, a blank
  password field keeps the stored API secret).

### Fixed

- Validation constraints in the `everypay` group (e.g. required credential
  fields) never actually ran: Sylius pins the gateway-config form subtree to
  the `sylius` validation group, so the factory-specific groups from
  `sylius_payment.gateway_config.validation_groups` do not reach it. The
  gateway configuration form now declares its own groups.

## [0.1.2] - 2026-07-06

Supersedes the retracted v0.1.1: its git history and test fixtures carried
leftover data from the source project.

### Added

- Project hygiene: SECURITY.md, CONTRIBUTING.md, this changelog, GitHub
  issue templates and an EveryPay trademark note.

- Functional test suite running the plugin inside the official
  `sylius/test-application` (SQLite, scripted EveryPay API mock): container
  wiring, notify endpoint, capture flow on the real payment-request command
  bus, admin gateway form rendering.
- Headless/API checkout support: the customer return URL can be passed as
  `after_pay_url` in the payment request payload; with `sylius/shop-bundle`
  installed the shop after-pay route remains the fallback. The container no
  longer requires the shop bundle to compile.
- CI matrix: PHP 8.2/8.3/8.4 x highest/lowest dependencies (the lowest
  resolution covers the Symfony 6.4 line).
- `payment_description` on one-off payments (bank-statement text for Open
  Banking, sanitized to the allowed charset and capped at 65 characters).
- A logged warning when the processing account name suggests a different
  currency than the payment (EveryPay would reject the payment at redirect
  time otherwise).

### Fixed

- Conflict with `payum/core` < 1.7.3, which declares psr/log 3 support but
  fatals on autoload.

## 0.1.1 - 2026-07-05 [RETRACTED]

First public release, extracted from a production Sylius 2 shop and verified
end-to-end against the EveryPay/SEB demo environment.

### Added

- One-off payments via the EveryPay hosted payment page on the Sylius 2
  PaymentRequest abstraction (no Payum).
- Idempotent payment state synchronization: customer return and server
  callbacks both re-verify against the EveryPay API; callbacks are never
  trusted.
- Full refunds from the admin Refund button, executed transactionally;
  portal-initiated refunds are recognized and never trigger a second refund
  API call.
- Admin gateway configuration form (encrypted at rest) with twig hooks,
  fixtures support (`use_payum` forced off), and translations in English,
  Lithuanian, Estonian and Latvian.
- Unit test suite (31 tests), phpstan level 9, Sylius Labs coding standard.

> v0.1.0 was retracted minutes after publishing (identical functionality).

[Unreleased]: https://github.com/pkglt/sylius-everypay-plugin/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/pkglt/sylius-everypay-plugin/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/pkglt/sylius-everypay-plugin/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/pkglt/sylius-everypay-plugin/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/pkglt/sylius-everypay-plugin/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/pkglt/sylius-everypay-plugin/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/pkglt/sylius-everypay-plugin/releases/tag/v0.1.2
