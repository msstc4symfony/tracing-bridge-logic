# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/tracing-bridge-logic`, namespace `Msstc4Symfony\TracingBridgeLogic`, extension
alias `msstc4symfony_tracing_bridge_logic`, no configuration tree) that connects `msstc4symfony/logic-bundle` to
`msstc4symfony/tracing-bundle`: every Action run gets its own span id, a child of the calling unit's span.
PHP >= 8.2, Symfony 7.4 / 8.x.

## Common commands

Develop against the CI profile: `COMPOSER=composer-ci.json composer install`.

- `make check` — `php -l`, PHPStan level 10, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-ci.json make check`.
- `make test` — unit + integration suites; `make infection`, `make fix`.
- `php ../bundle-standard/bin/verify-standard.php .` — standard compliance (the same check CI runs).

## Architecture in 60 seconds

- `Infrastructure\Tracing\TracingMiddleware` (`@internal`) — logic-bundle `ActionMiddlewareInterface`, priority 600
  (outermost, outside profiling 500, metrics 400 and logging 300). `$outer = current(); replace($outer->withChildSpan());
  try … finally replace($outer)` on tracing-bundle's `TraceContextHolderInterface`.
- Needs tracing-bundle ^2.0 (`^2.0@rc` until final) and logic-bundle ^1.3. tracing 2.0 has no lazily created ids, so
  `current()` is taken as is.
- `TracingBridgeLogicBundle` only imports `src/Resources/config/services.php`; without TracingBundle autowiring fails.

Details: `.claude/docs/architecture.md`. **Read `.claude/docs/known-issues.md` before chasing a
"weird" failure.**

## Pointers

- `.claude/docs/architecture.md` — layers, wiring, service ids.
- `.claude/docs/conventions.md` — layout, tests, commits.
- `.claude/docs/known-issues.md` — bridge and family-wide Symfony/CI traps.
