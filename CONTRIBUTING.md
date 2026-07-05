# Contributing

Thanks for considering a contribution! This project is a community EveryPay
integration for Sylius 2 — issues and pull requests are welcome.

## Getting started

```bash
git clone https://github.com/pkglt/sylius-everypay-plugin.git
cd sylius-everypay-plugin
composer install
```

No database service, browser or frontend build is needed: the functional
suite boots the official `sylius/test-application` on SQLite with a scripted
EveryPay API mock.

## Quality gates

All of these must be green before a PR is ready:

```bash
vendor/bin/phpunit             # unit + functional suites
vendor/bin/phpstan analyse     # level 9 — no baselines, no ignores
vendor/bin/ecs check --fix     # Sylius Labs coding standard
```

CI additionally runs the test suites on PHP 8.2–8.4 with both highest and
lowest dependency resolutions (`composer update --prefer-lowest
--prefer-stable` reproduces the latter locally). After changing DI or config
files, clear the test kernel cache first: `rm -rf var/cache`.

## Guidelines

- Read [AGENTS.md](AGENTS.md) first — it is the canonical map of the
  architecture, the wiring conventions and the **invariants** (never trust
  callbacks, payments stay `new` until EveryPay reports otherwise, the refund
  loop guard, …). PRs that break an invariant will be declined regardless of
  green CI.
- Keep translations in sync across all four locales (`en`, `lt`, `et`, `lv`).
  Estonian/Latvian corrections from native speakers are especially welcome.
- Commit messages: imperative mood, plain sentences, no conventional-commit
  prefixes.
- New behaviour needs tests — unit tests for logic, a functional test when
  the behaviour depends on Sylius wiring.

## Reporting issues

Use the issue templates. For security problems, follow
[SECURITY.md](SECURITY.md) instead of opening a public issue.
