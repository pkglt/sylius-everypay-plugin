# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Functional test suite running the plugin inside the official
  `sylius/test-application` (SQLite, scripted EveryPay API mock): container
  wiring, notify endpoint, capture flow on the real payment-request command
  bus, admin gateway form rendering.
- Headless/API checkout support: the customer return URL can be passed as
  `after_pay_url` in the payment request payload; with `sylius/shop-bundle`
  installed the shop after-pay route remains the fallback. The container no
  longer requires the shop bundle to compile.
- CI matrix: PHP 8.2/8.3/8.4 × highest/lowest dependencies (the lowest
  resolution covers the Symfony 6.4 line).
- `payment_description` on one-off payments (bank-statement text for Open
  Banking, sanitized to the allowed charset and capped at 65 characters).
- A logged warning when the processing account name suggests a different
  currency than the payment (EveryPay would reject the payment at redirect
  time otherwise).

### Fixed

- Conflict with `payum/core` < 1.7.3, which declares psr/log 3 support but
  fatals on autoload.

## [0.1.1] - 2026-07-05

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

[Unreleased]: https://github.com/pkglt/sylius-everypay-plugin/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/pkglt/sylius-everypay-plugin/releases/tag/v0.1.1
