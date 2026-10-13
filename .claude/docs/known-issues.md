# Известные ловушки

## Бридж

- **Ленивые id tracing-bundle.** `getTraceParent()`/`getRequestId()` создают значения при первом обращении. Если
  первое обращение случится внутри Action, `restore($outer)` их отбросит, и код после Action получит другой
  trace id и request id. Поэтому middleware трогает оба до `snapshot()`; тест
  `testIdsNotCreatedYetAreSharedByTheActionAndTheUnit`.
- **`continueTrace()` не подходит** для span Action: он переписывает `remoteTraceParent` и `traceState`.
- **`KernelBridgeTest::testTheMiddlewareRunsOutsideLogging` читает приватное `ActionPipeline::$middlewares`**
  через reflection — публичного API порядка у logic-bundle нет.
- **Копирование полей `TraceContext` живёт в tracing-bundle** (`withChildSpan()`, с 1.2/бридж 1.1.0): в 1.0.0
  middleware копировал их сам, и новое поле молча становилось default внутри Action. Без `getTraceParent()` перед
  `snapshot()` `withChildSpan()` бросит `LogicException`.
- **Необработанное исключение Action попадает в Sentry со span единицы работы**: обработчик ядра/консоли видит его
  после `restore()` в `finally`. Связь с Action — лог «Fail application action» (он пишется внутри span).
- **Изменения trace внутри Action отбрасываются** при выходе (`setRequestId`, `continueTrace`, reset) — так задумано.
  Стек snapshot/restore на общем `RequestIdService` не годится для Fiber/async, как и сам tracing-bundle.
- **Span Action нигде не экспортируется**: tracing-bundle только кладёт id в логи, Sentry и исходящие заголовки.
  Дерево восстанавливается по `span_id`/`parent_span_id` в логах.

## Семейство (Symfony, CI)

- **`bin/init` шаблона даёт namespace с суффиксом `Bundle`** и имя пакета `…-bundle` (историческое: при создании
  репозитория); у бриджей семейства без суффикса — поправлено вручную (composer, deptrac, README, SECURITY, CI slug).
- **`kernel.build_dir` в тестовом контейнере.** `PhpFileLoader` Symfony 7.4 читает этот параметр,
  8.x — нет; без него интеграционные тесты падают только в ячейке 7.4.
- **Теги для своих интерфейсов** регистрировать в `loadExtension()` через
  `registerForAutoconfiguration()`, а не `#[AutoconfigureTag]` на интерфейсе: атрибут на интерфейсе
  из vendor-пакета приложение не подхватывает.
- **`gh run watch` может вернуть 0 на упавшем прогоне.** Итог проверять через
  `gh run view <id> --json conclusion,jobs`.
- **`actions/checkout` по sha делает shallow-клон**; push из такого клона отклоняется как
  "fetch first" — нужен `fetch-depth: 0`.
- **Пороги Infection** (`infection-min-msi`, `infection-min-covered-msi` в `checks.yml`) = замер минус запас,
  с датой замера (UTC) в комментарии; пересчитывать при заметном изменении тестов.
- **`exec()` портит вывод с путями**: режет по строкам и срезает пробелы в конце строки, поэтому
  `git ls-files -z` через него теряет символы в именах. Читать через `proc_open` целиком. В тестах не
  читать stdout и stderr из двух pipe по очереди (deadlock при заполненном буфере) — stderr писать в файл.
