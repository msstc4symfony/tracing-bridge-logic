# msstc4symfony/tracing-bridge-logic

![Build Status](https://github.com/msstc4symfony/tracing-bridge-logic/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Runs every [logic-bundle](https://github.com/msstc4symfony/logic-bundle) Action in its own
[tracing-bundle](https://github.com/msstc4symfony/tracing-bundle) span, so the logs and outgoing calls of one Action
run share a `span_id`, and its `parent_span_id` points at the request, command, message or Action that called it.

## Compatibility

| Bundle | PHP  | Symfony  | logic-bundle | tracing-bundle |
|--------|------|----------|--------------|----------------|
| 1.2+   | 8.2+ | 7.4, 8.x | ^1.2         | ^1.3           |
| 1.1    | 8.4+ | 7.4, 8.x | ^1.1         | ^1.2           |
| 1.0    | 8.4+ | 7.4, 8.x | ^1.1         | ^1.1           |

Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

## Installation

The packages are not on Packagist yet, so register their GitHub repositories first:

```sh
composer config repositories.msstc4symfony-logic vcs https://github.com/msstc4symfony/logic-bundle
composer config repositories.msstc4symfony-tracing vcs https://github.com/msstc4symfony/tracing-bundle
composer config repositories.msstc4symfony-tracing-bridge-logic vcs https://github.com/msstc4symfony/tracing-bridge-logic
composer require msstc4symfony/tracing-bridge-logic
```

Register the bridge in `config/bundles.php` alongside the two bundles it connects (order does not matter):

```php
Msstc4Symfony\TracingBundle\TracingBundle::class => ['all' => true],
Msstc4Symfony\LogicBundle\LogicBundle::class => ['all' => true],
Msstc4Symfony\TracingBridgeLogic\TracingBridgeLogicBundle::class => ['all' => true],
```

Nothing to configure. Without TracingBundle the container fails to compile: `TracingMiddleware` cannot be
autowired, as no `RequestIdServiceInterface` service exists.

## The span

While an Action runs:

- `span_id` is new, `parent_span_id` is the span id of the unit of work (or Action) that called it;
- trace id, request id, `request from`, the received `traceparent` and `tracestate` stay those of the unit;
- HTTP requests and messages sent by the Action carry its span as their parent;
- an Action called from another Action gets a child of the caller's span.

Afterwards the unit's trace is restored exactly, also when the Action throws; the exception is rethrown unchanged.
Sentry events captured while the Action runs are tagged with its span; an exception that escapes the Action reaches
Sentry after the restore, so it carries the unit's span — the "Fail application action" log links it to the Action.
Changes an Action makes to the trace itself (request id, `continueTrace()`, reset) end with it.
logic-bundle's "Start / Finish / Fail application action" logs are written inside the span, so they link a `span_id`
to the Action's name.

The bridge is an Action middleware at priority 600, the outermost one: the span also covers
[profiling-bridge-logic](https://github.com/msstc4symfony/profiling-bridge-logic) (500),
[metrics-bridge-logic](https://github.com/msstc4symfony/metrics-bridge-logic) (400), logging (300), validation (200)
and lock (100).

tracing-bundle exports no spans: the call tree is rebuilt from the `span_id` / `parent_span_id` log keys.

## Local development

`composer-ci.json` installs logic-bundle and tracing-bundle from their GitHub repositories.

```sh
make install-ci                        # composer-ci.json profile with every CI tool
COMPOSER=composer-ci.json make check   # lint, PHPStan, CS, composer validate/audit, Rector, deptrac
make test                              # unit + integration
```

## License

MIT — see [LICENSE](LICENSE).
