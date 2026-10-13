# Соглашения

- Use-case — каталог `src/Application/<Aggregate>/<UseCase>/` с `Action` (`final readonly`, `__invoke(Input): Output`),
  `Input` (инварианты в конструкторе) и `Output`.
- Тесты: `tests/Unit/<путь класса>`; `tests/Integration` — реальный `ContainerBuilder` через
  `TestContainer::compile($config)`, `MakeBundleServicesPublicPass` открывает сервисы бандла.
- Файлы инструментов байт-в-байт из `bundle-standard/templates`; менять их можно только в стандарте.
- Коммиты — одна строка на английском, ≤ 20 слов; теги `vX.Y.Z`.
