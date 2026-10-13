# Архитектура

- `SkeletonBundle` — `AbstractBundle`: `configure()` описывает дерево `msstc4symfony_skeleton`,
  `loadExtension()` кладёт значения в параметры (`SkeletonBundle::GREETING_PARAMETER`) и импортирует
  `src/Resources/config/services.php`.
- Сервисы бандла приватные, id = FQCN; потребитель получает их автовайрингом.
- Слои (`deptrac.yaml`): Domain ← DomainService ← Application ← Infrastructure / Presentation / Framework;
  DependencyInjection видит только Application; BundleRoot — Application и DependencyInjection.
  Domain/DomainService различаются подкаталогами `src/Domain/` (данные vs операции).
- Пример `Application/Greeting/Greet` — заглушка, заменить реальным use-case.
