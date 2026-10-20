# Соглашения

- Код бриджа — в `src/Infrastructure/Tracing/`; Application-слоя нет.
- Тесты: `tests/Unit/<Класс>Test.php` с `#[CoversClass]`; unit-тест middleware работает с
  `tests/Fixtures/InMemoryTraceHolder` (держатель tracing 2.0 — `@internal`) и читает `current()` внутри `$next`.
- `tests/Integration/KernelBridgeTest` поднимает настоящий `TestKernel` (Framework, Monolog, Tracing, Logic, бридж)
  и читает `extra` записей logic-bundle из `monolog.handler.main` (тип `test`).
- Длинные строки в тестах допустимы: конфиг CS семейства их не ограничивает.
- Фикстуры `Echo*`, `FailingAction`, `InnerAction`, `ExceptionHandlerSnapshot` скопированы из profiling-bridge-logic
  (namespace заменён).
- Infection гоняет только unit-сьют: всё, что должно убивать мутантов, — в `tests/Unit`.
- Файлы инструментов байт-в-байт из `bundle-standard/templates`; менять их можно только в стандарте.
- Коммиты — одна строка на английском, ≤ 20 слов; теги `vX.Y.Z`.
