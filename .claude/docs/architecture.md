# Архитектура

- `TracingBridgeLogicBundle` — `AbstractBundle` без дерева конфигурации и без `build()`: `loadExtension()`
  импортирует `src/Resources/config/services.php`.
- `Infrastructure/Tracing/TracingMiddleware` — `#[AsActionMiddleware(priority: 600)]`, самый внешний в пайплайне
  logic-bundle: внутри span Action оказываются profiling-bridge-logic (500), metrics-bridge-logic (400),
  логирование (300), валидация (200) и lock (100). Логи «Start/Finish/Fail application action» logic-bundle несут
  `span_id` запуска и тем связывают его с именем Action.
  - Алгоритм (2.0): `$outer = current()`, `replace($outer->withChildSpan())` (тот же runtime/trace/baggage, новый
    span, `parentSpanId` = span единицы работы), `finally` — `replace($outer)` (тот же объект).
  - Только публичный API tracing-bundle 2.0: `Application\Port\TraceContextHolderInterface`, `Domain\ValueObject\TraceContext`.
    `continuing()` не используется — он подменил бы `remoteTraceParent`.
  - Вложенный Action — дочерний span вызывающего Action; исходящие HTTP/сообщения из Action несут его span
    (`outgoing()` берёт текущий span).
- Сервис приватный, id = FQCN, `@internal`. Без TracingBundle контейнер не компилируется (нет `TraceContextHolderInterface`).
- deptrac: код только в Infrastructure; BundleRoot от него не зависит.
- Требует tracing-bundle ^2.0 (`^2.0@rc` до финала) и logic-bundle ^1.3 (2.0.0, этап B4).
