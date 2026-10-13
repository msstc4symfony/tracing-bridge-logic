# Changelog

## 1.1.0 - 2026-10-13

- Needs tracing-bundle ^1.2: the Action's span comes from `TraceContext::withChildSpan()`, so a field added to
  `TraceContext` later is carried into the Action without a bridge release.

## 1.0.0 - 2026-10-13

- First release: every logic-bundle Action runs in its own tracing-bundle span id, a child of the calling unit's span.
