# Security Policy

This plugin handles payment flows, so we take security reports seriously.

## Supported versions

| Version | Supported |
|---------|-----------|
| latest 0.x release | yes |
| older releases | no - upgrade first |

## Reporting a vulnerability

**Please do not open a public GitHub issue for security problems.**

Report vulnerabilities privately:

- GitHub: use [private vulnerability reporting](https://github.com/pkglt/sylius-everypay-plugin/security/advisories/new) on this repository, or
- E-mail: alfonsas.cirtautas@gmail.com (mention "sylius-everypay-plugin" in the subject)

Include a description of the issue, steps to reproduce, and the affected
version. You will get an acknowledgement within a few days; please allow a
reasonable disclosure window before publishing details.

## Scope notes

- The plugin never trusts EveryPay callbacks or return-URL query parameters -
  every state change is re-verified against the authenticated EveryPay API.
  Reports demonstrating a way around that re-verification are the highest
  priority.
- Gateway credentials are encrypted at rest by Sylius (`sylius_gateway_config`).
- Issues in the EveryPay platform itself should go to EveryPay
  (support@every-pay.com), not this repository.
