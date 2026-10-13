# Архитектура

- `TracingBridgeLogicBundle` — `AbstractBundle` без дерева конфигурации и без `build()`: `loadExtension()`
  импортирует `src/Resources/config/services.php`.
- `Infrastructure/Tracing/TracingMiddleware` — `#[AsActionMiddleware(priority: 600)]`, самый внешний в пайплайне
  logic-bundle: внутри span Action оказываются profiling-bridge-logic (500), metrics-bridge-logic (400),
  логирование (300), валидация (200) и lock (100). Логи «Start/Finish/Fail application action» logic-bundle несут
  `span_id` запуска и тем связывают его с именем Action.
  - Алгоритм: `getTraceParent()` и `getRequestId()` (фиксируют лениво создаваемые id до snapshot), `snapshot()`,
    `restore($outer->withChildSpan())` (tracing-bundle 1.2: `traceParent = unitSpan->child()`,
    `parentSpanId = unitSpan->parentId`, остальные поля без изменений); `finally` — `restore($outer)`.
  - Только публичный API tracing-bundle (`RequestIdServiceInterface`, `TraceContext`); `continueTrace()` не
    используется — он подменил бы `remoteTraceParent` (внутри Action `getRemoteTraceParent()` вернул бы span
    единицы работы, а не пришедший по сети).
  - Вложенный Action — дочерний span вызывающего Action; исходящие HTTP/сообщения из Action несут его span
    (`createOutgoingTraceParent()` = текущий span с tracing-bundle 1.1).
- Сервис приватный, id = FQCN. Без TracingBundle контейнер не компилируется (нет `RequestIdServiceInterface`).
- deptrac: код только в Infrastructure; BundleRoot от него не зависит.
- Требует tracing-bundle ^1.2 (`TraceContext::withChildSpan()`, `extra.parent_span_id`).
