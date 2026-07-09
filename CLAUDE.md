# CLAUDE.md

The canonical agent guide for this repository is **AGENTS.md** - read it
first; everything there applies to Claude Code sessions in full.

@AGENTS.md

## Claude-specific notes

- Quality gates before declaring work done: `vendor/bin/phpunit`,
  `vendor/bin/phpstan analyse` (level 9), `vendor/bin/ecs check` - all green.
- Commit style: imperative mood, plain sentences (e.g. "Add refund guard for
  portal-initiated refunds"), no conventional-commit prefixes, **no
  Co-Authored-By / AI attribution trailers**.
- The invariants section of AGENTS.md is non-negotiable - when a change
  appears to require breaking one (e.g. "why does the payment stay `new`?"),
  stop and surface the conflict instead of "fixing" it.
- When touching translations, update all four locales (en, lt, et, lv)
  together; et/lv are machine-assisted - mark uncertain phrasing in the PR
  description rather than guessing silently.
