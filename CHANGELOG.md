# Changelog

## 2.0.1 - 2026-10-23

- Changed: allows logic-bundle 2.0 (`^1.3|^2.0`); 2.0 only marks logic-bundle internals `@internal`, none of
  which the bridge uses.

## 2.0.0 - 2026-10-20

- Changed: requires the final 2.0 releases (`^2.0` instead of `^2.0@rc`).
- Changed: README, CLAUDE.md and project notes completed after review; tests for a nested Action that throws and for
  outgoing calls from an Action.

## 2.0.0-rc.1 - 2026-10-20

- Changed: requires tracing-bundle ^2.0 (`^2.0@rc` until tracing 2.0.0 is final): `TracingMiddleware` takes
  `Application\Port\TraceContextHolderInterface` and runs the Action in `current()->withChildSpan()`, putting the
  unit's context back afterwards; logic-bundle ^1.3.
- Changed: `TracingMiddleware` is `@internal`; the public API is the bundle class (README "Public API").
- Changed: PHPStan enforces `#[\Override]`; adopts bundle-standard v3 (canonical deptrac layers, anchored `BundleRoot`).
- Upgrading from 1.x: upgrade tracing-bundle to 2.0 (its own upgrade notes apply); the bridge has no configuration.

## 1.2.0 - 2026-10-14

- Changed: requires PHP 8.2 instead of 8.4 (Symfony 8 still needs PHP 8.4).
- Changed: `TracingMiddleware::PRIORITY` no longer declares a native type (PHP 8.2 has none); value unchanged.
- Changed: requires logic-bundle ^1.2 and tracing-bundle ^1.3, their PHP 8.2 releases.

## 1.1.0 - 2026-10-13

- Needs tracing-bundle ^1.2: the Action's span comes from `TraceContext::withChildSpan()`, so a field added to
  `TraceContext` later is carried into the Action without a bridge release.

## 1.0.0 - 2026-10-13

- First release: every logic-bundle Action runs in its own tracing-bundle span id, a child of the calling unit's span.
