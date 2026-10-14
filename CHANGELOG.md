# Changelog

## 1.2.0 - 2026-10-14

- Changed: requires PHP 8.2 instead of 8.4 (Symfony 8 still needs PHP 8.4).
- Changed: `TracingMiddleware::PRIORITY` no longer declares a native type (PHP 8.2 has none); value unchanged.
- Changed: requires logic-bundle ^1.2 and tracing-bundle ^1.3, their PHP 8.2 releases.

## 1.1.0 - 2026-10-13

- Needs tracing-bundle ^1.2: the Action's span comes from `TraceContext::withChildSpan()`, so a field added to
  `TraceContext` later is carried into the Action without a bridge release.

## 1.0.0 - 2026-10-13

- First release: every logic-bundle Action runs in its own tracing-bundle span id, a child of the calling unit's span.
