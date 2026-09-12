# План: 001-quality-tooling-bootstrap

## Связь с требованиями

Задача процессно-инструментальная: включить уже описанный в `docs/QUALITY.md`
целевой QA-набор и получить единый gate `make qa` / `composer qa`. Доменной
логики и новых терминов нет; `docs/UBIQUITOUS_LANGUAGE.md` не меняется.

Решения G1 (обязательны) разворачиваются в плане так:

| G1 | Как реализуется в плане | Шаги |
|---|---|---|
| Q1 — минимальный deptrac под текущие namespace | `deptrac.yaml` описывает as-is слои `App\*` + внешние `Symfony/Doctrine/Psr/Cron`, ruleset «как есть» | Ш3 |
| Q2 — clover сейчас, условный порог ≥90% для `src/*/Domain`/`src/*/Application` | `phpunit.xml.dist` генерирует clover; `tools/check-coverage.php` применяет порог по факту наличия каталогов | Ш5 |
| Q3 — PCOV в образе (локально + CI) | `pcov` в `dev`-стадии `Dockerfile`; `coverage: pcov` в setup-php | Ш5, Ш7 |
| Q4 — точечный `@phpstan-ignore` с причиной и датой, baseline запрещён | точные ignore только на framework/Doctrine-границах; единый формат `(причина; review YYYY-MM-DD)` | Ш4 |
| Q5 — включить `doctrine:schema:validate --env=test` | добавляется в CI **после** `doctrine:migrations:migrate`; при дрейфе — эскалация, не молчаливая правка | Ш7 |
| Q6 — `declare_strict_types` на весь проект | правило CS-Fixer + finder без сужения; фактически затрагиваются все `.php` под `src/`, `tests/`, `config/`, `public/`, `migrations/` | Ш2 |

Соответствие AC: AC1–AC2 → Ш4; AC3–AC5 → Ш2/Ш3; AC6 → Ш3; AC7–AC8 → Ш6; AC9–AC10
→ Ш5; AC11–AC12 → Ш7; AC13 → Ш4 (проверка отсутствия baseline и формата ignore).

### Решения, утверждённые на G2

| Вопрос | Решение |
|---|---|
| Утверждение плана | `plan.md` утверждён как основа реализации задачи 001 |
| `phpstan/phpstan-phpunit` | **Разрешить добавить при необходимости**, если L10 в `tests/` не закрывается аннотациями |
| Пакет deptrac | **`deptrac/deptrac`** (современный поддерживаемый вместо `qossmic/deptrac`); синхронизировать упоминание в `docs/QUALITY.md` §2.2 в рамках задачи |
| Tooling-файлы | **Согласовано:** `tools/check-coverage.php` (AC10) и `tests/object-manager.php` (phpstan-doctrine) |
| Критерий эскалации | **Принят:** > ~5 `@phpstan-ignore` либо необходимость менять сигнатуры/схему — остановка и эскалация человеку |

Следствие для Ш1: вместо `qossmic/deptrac` ставим `deptrac/deptrac`. Следствие
для Ш4/AC2: список PHPStan-расширений может быть дополнен
`phpstan/phpstan-phpunit`, если это единственный способ закрыть L10 в тестах.
Следствие для документации: строку `composer require --dev qossmic/deptrac` в
`docs/QUALITY.md` §2.2 заменить на `deptrac/deptrac` (одна строка, без смены
стандарта).

## Затрагиваемые модули и слои

- `App\` — весь текущий монолит (слои as-is: `Controller`, `Form`, `Dto`,
  `Entity`, `Repository`, `Service`, `Command`, `EventSubscriber`, `Schedule`,
  `Kernel`). Архитектурная миграция в `Secret`/`Crypto`/`Shared` **не
  выполняется** (out of scope).
- Инфраструктура сборки: `Dockerfile` (dev-стадия), `Makefile`, `composer.json`,
  `phpstan.neon`, `.php-cs-fixer.dist.php`, `phpunit.xml.dist`,
  `.github/workflows/ci.yml`, новый `deptrac.yaml`.
- `deptrac` анализирует только `src/` (как в `docs/QUALITY.md` §2.2); `tests/` и
  tooling вне его путей.

## Изменение модели/контрактов (агрегаты, VO, порты)

**Нет.** Задача не меняет:

- агрегаты/VO/порты (их пока нет в целевой структуре);
- публичные сигнатуры методов, маршруты, схему БД, криптографию и тексты
  ошибок (AC5);
- доменные события.

`declare(strict_types=1)`, global imports, PHPDoc/аннотации и форматирование —
не изменение контрактов. Любая правка, требующая смены сигнатуры или схемы,
останавливает задачу и эскалируется.

## Тест-план

Задача инструментальная: новых доменных тестов не появляется, существующие
тесты не переписываются. Проверка AC — прогон инструментов + точечные
верификационные команды. Для AC, которые нельзя доказать PHPUnit, фиксируем
явную команду/критерий.

- **unit:**
  - `tests/Unit/Service/EncryptionServiceTest.php` — оставляем как есть
    (добавляется только `declare_strict_types`); он ловит регрессию
    strict_types в `EncryptionService`.
  - Новых unit-тестов не добавляем. `tools/check-coverage.php` — тонкий
    tooling, покрытие порогов проверяем верификационным сценарием (ниже), а не
    постоянным тестом в `tests/`.
- **application:** слоя Application/портов пока нет — проверять нечего.
- **functional:**
  - `tests/Functional/PasteFlowTest.php` — оставляем сценарии без изменения
    поведения; допустима только типовая правка (категория 2 в разделе
    «Ожидаемые ошибки PHPStan level 10»). Тест обязателен
    как регрессия strict_types на границе HTTP/форм/Doctrine.
- **Верификация AC командами:**
  - AC1/AC2/AC13: `make stan` → exit 0; отдельно проверить отсутствие
    `phpstan-baseline.neon` и наличие `include` расширений.
  - AC3: `make cs-check` → exit 0; ревизия списка правил в
    `.php-cs-fixer.dist.php`.
  - AC4: скан `src/**/*.php` на `declare(strict_types=1);` как первой
    инструкции + `make test` зелёный.
  - AC5: ревью diff по `src/`/`tests/`.
  - AC6: `make deptrac` → exit 0, нет violations (uncovered не считается
    нарушением).
  - AC7: `make qa` → exit 0; искусственно проверить, что падение шага даёт
    ненулевой код (например, временный заведомо неверный `cs-check`).
  - AC8: `composer qa` → последовательно `@cs-check`, `@stan`, `@deptrac`,
    `@test`.
  - AC9: `make coverage` → существует непустой `var/coverage/clover.xml`,
    тесты зелёные.
  - AC10: верификационный сценарий — временно создать `src/Smoke/Domain/Foo.php`,
    сгенерировать clover и убедиться, что `tools/check-coverage.php` падает при
    <90% и проходит при ≥90%; затем удалить временный каталог. Пока каталогов
    `src/*/Domain`/`src/*/Application` нет — checker печатает «нет каталогов» и
    выходит 0 (нет ложного падения).
  - AC11: прогон job `test` в CI (или локальная имитация команд 1:1).
  - AC12: в логах CI и clover нет `APP_SECRET`, plaintext, ключей, содержимого
    `.env.local`; clover не содержит исходников/значений.

## Пошаговый план реализации

Порядок обеспечивает, что каждый коммит обратим, а `make qa` становится зелёным
инкрементально (сначала зависимости/конфиги, затем `strict_types`, затем
точечные правки кода).

### Ш0. Baseline
- Убедиться, что `make up` и `make test` зелёные, `make stan` проходит на
  level 8.
- Зафиксировать вывод `vendor/bin/phpstan analyse` до изменений (для сравнения
  объёма на Ш4).

### Ш1. Dev-зависимости (`composer.json`/`composer.lock`)
- `composer require --dev`:
  - `phpstan/phpstan-doctrine:^2.0`,
  - `phpstan/phpstan-strict-rules:^2.0`,
  - `phpstan/phpstan-deprecation-rules:^2.0`,
  - `qossmic/deptrac` — последняя стабильная, совместимая с PHP 8.4
    (проверить фактический мажор/имя пакета при установке; при переименовании
    пакета — эскалация на G2, зафиксировать в `composer.lock`).
- Коммит: `chore: add qa tooling dev dependencies`.

### Ш2. CS-Fixer + `declare_strict_types` (Q6)
- `.php-cs-fixer.dist.php`: добавить правила `declare_strict_types`,
  `strict_comparison`, `strict_param`, `void_return`, `ordered_imports`
  (`sort_algorithm: alpha`), `no_unused_imports`,
  `global_namespace_import` (`import_classes => true`) поверх `@Symfony`.
- Выполнить `make cs-fix`, затем `make cs-check`.
- Ожидаемый diff (механический, AC5-совместимый):
  - `declare(strict_types=1);` в 13 файлах `src/`, `tests/Unit/...`,
    `tests/bootstrap.php`, `public/index.php`, `config/bootstrap.php`,
    `config/preload.php` (`migrations/Version20260909000000.php` и
    `tests/Functional/PasteFlowTest.php` уже содержат `declare`);
  - `global_namespace_import` добавит `use DateInterval;`,
    `use DateTimeImmutable;`, `use RuntimeException;` в
    `PasteController`, `Paste`, `PasteRepository`,
    `PurgeExpiredPastesCommand`, `PasteFlowTest`;
  - форматирование `}$next` в `PurgeExpiredPastesCommand`,
    `SQLitePragmaSubscriber`, `Paste::blob()`.
- `config/bundles.php` и `config/reference.php` исключены через `notPath` и не
  трогаются; `bin/console`/`bin/phpunit` без расширения `.php` finder не
  подхватывает.
- Прогнать `make test`: strict_types не должен менять поведение (см. «Риски»).
- Коммит: `chore: enforce strict_types and cs rules`.

### Ш3. deptrac (Q1) + Makefile/composer цели
- Создать `deptrac.yaml` (минимальный, as-is):

  ```yaml
  deptrac:
    paths: [src]
    layers:
      - { name: Kernel,          collectors: [{ type: classNameRegex, value: '#^App\\Kernel$#' }] }
      - { name: Schedule,        collectors: [{ type: classNameRegex, value: '#^App\\Schedule$#' }] }
      - { name: Controller,      collectors: [{ type: classNameRegex, value: '#^App\\Controller\\#' }] }
      - { name: Form,            collectors: [{ type: classNameRegex, value: '#^App\\Form\\#' }] }
      - { name: Dto,             collectors: [{ type: classNameRegex, value: '#^App\\Dto\\#' }] }
      - { name: Entity,          collectors: [{ type: classNameRegex, value: '#^App\\Entity\\#' }] }
      - { name: Repository,      collectors: [{ type: classNameRegex, value: '#^App\\Repository\\#' }] }
      - { name: Service,         collectors: [{ type: classNameRegex, value: '#^App\\Service\\#' }] }
      - { name: Command,         collectors: [{ type: classNameRegex, value: '#^App\\Command\\#' }] }
      - { name: EventSubscriber, collectors: [{ type: classNameRegex, value: '#^App\\EventSubscriber\\#' }] }
      - { name: Symfony,         collectors: [{ type: classNameRegex, value: '#^Symfony\\#' }] }
      - { name: Doctrine,        collectors: [{ type: classNameRegex, value: '#^Doctrine\\#' }] }
      - { name: Psr,             collectors: [{ type: classNameRegex, value: '#^Psr\\#' }] }
      - { name: Cron,            collectors: [{ type: classNameRegex, value: '#^Cron\\#' }] }
    ruleset:
      Kernel: [Symfony]
      Schedule: [Symfony, Cron]
      Controller: [Form, Dto, Entity, Repository, Service, Symfony, Doctrine]
      Form: [Dto, Symfony]
      Service: [Dto]
      Entity: [Dto, Doctrine, Repository]
      Repository: [Entity, Doctrine]
      Command: [Repository, Doctrine, Symfony]
      EventSubscriber: [Doctrine, Symfony]
    skip_violations: {}
  ```

  Обоснование неочевидных рёбер:
  - `Entity -> Repository` — `Paste` ссылается на `PasteRepository::class` в
    `#[ORM\Entity(repositoryClass: ...)]` (as-is, допустимо).
  - `Controller -> Doctrine` — параметр `EntityManagerInterface` в
    `PasteController::create`.
  - внешние слои `Symfony/Doctrine/Psr/Cron` описаны явно, чтобы не плодить
    uncovered и не ссылаться на несуществующие слои в ruleset.
- `Makefile`: цели `deptrac`, `cs-check` (и `.PHONY`). Позже — `qa`, `coverage`.
- `composer.json scripts`: `cs-check`, `deptrac` (без `qa` до Ш6).
- `make deptrac` → exit 0.
- Коммит: `chore: add deptrac layer rules`.

### Ш4. PHPStan level 10 (AC1, AC2, AC13)
- `phpstan.neon` целевой формы:

  ```neon
  includes:
      - vendor/phpstan/phpstan-symfony/extension.neon
      - vendor/phpstan/phpstan-doctrine/extension.neon
      - vendor/phpstan/phpstan-strict-rules/rules.neon
      - vendor/phpstan/phpstan-deprecation-rules/rules.neon
  parameters:
      level: 10
      paths: [src, tests]
      tmpDir: var/phpstan
      treatPhpDocTypesAsCertain: false
      checkUninitializedProperties: true
      reportUnmatchedIgnoredErrors: true
      symfony:
          containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
      doctrine:
          objectManagerLoader: tests/object-manager.php
  ```

- Добавить `tests/object-manager.php` (стандартный Symfony/phpstan-doctrine
  loader: boot kernel, вернуть `EntityManagerInterface`). Если loader даёт
  проблемы bootstrap/CI — fallback: убрать `doctrine.objectManagerLoader` и
  опереться на `@extends ServiceEntityRepository<Paste>`.
  - `symfony.containerXmlPath` требует прогретого dev-контейнера. В CI он
    появляется после `composer install` (`post-install-cmd: cache:clear`). Для
    локального «чистого» прогона предусмотреть в `make stan` прогрев
    (`php bin/console cache:warmup --env=dev`) **до** `phpstan analyse`; если
    контейнер не нужен — оставить только fallback без пути.
- Запустить `make stan` и устранять ошибки по категориям (см. раздел
  «Ожидаемые ошибки L10»): сначала типовые правки/аннотации, `@phpstan-ignore`
  — только в конце и только с обоснованием.
- `reportUnmatchedIgnoredErrors: true` → проверить, что существующий ignore в
  `Paste::$id` всё ещё матчится; при смене идентификатора — актуализировать.
- Коммиты: отдельные `refactor:`/`chore:` по категориям (или один, если объём
  мал), миграций и смены поведения нет.

### Ш5. Покрытие (AC9, AC10) + PCOV (Q3)
- `phpunit.xml.dist`:
  ```xml
  <source>
      <include><directory suffix=".php">src</directory></include>
  </source>
  <coverage>
      <report><clover outputFile="var/coverage/clover.xml"/></report>
  </coverage>
  ```
  (`<source>` — обязательная фильтрация в PHPUnit 11; `<coverage>` содержит
  только report, без порогов.)
- `Dockerfile` dev-стадия: установить `pcov` рядом с xdebug
  (`pecl install xdebug pcov`), `docker-php-ext-enable xdebug pcov`.
  Продуктовые стадии не трогаются.
- Новый `tools/check-coverage.php`: читает `var/coverage/clover.xml`,
  определяет наличие каталогов, соответствующих `src/*/Domain` и
  `src/*/Application`; если их нет — выходит 0 с сообщением; если есть —
  суммирует statements/covered по файлам внутри и падает при <90%.
- `Makefile`:
  ```make
  coverage:
  	docker compose exec -e APP_ENV=test -e XDEBUG_MODE=off php php bin/phpunit --coverage-clover var/coverage/clover.xml
  	docker compose exec php php tools/check-coverage.php
  ```
  `XDEBUG_MODE=off` исключает конфликт драйверов: покрытие снимает PCOV.
- `var/coverage/` уже покрыт `.gitignore` (`/var/*`), правок не требует.
- Коммит: `chore: add coverage tooling and threshold check`.

### Ш6. Собрать `make qa` (AC7, AC8)
- Только когда `cs-check`, `stan`, `deptrac`, `test` зелёные:
  - `Makefile`: `qa: cs-check stan deptrac test` (+ `.PHONY`);
  - `composer.json`: `"qa": ["@cs-check", "@stan", "@deptrac", "@test"]`,
    `"test": "phpunit"`, `"stan": "phpstan analyse"`.
- `make qa` и `composer qa` → exit 0. Коммит: `chore: add unified qa gate`.

### Ш7. CI (AC11, AC12, Q5)
- `setup-php`: `coverage: pcov` (было `coverage: none`).
- В job `test` порядок команд:
  1. `php-cs-fixer fix --dry-run --diff`
  2. `vendor/bin/phpstan analyse`
  3. `vendor/bin/deptrac analyse`
  4. `php bin/console messenger:consume scheduler_default --time-limit=1`
  5. `php bin/console doctrine:migrations:migrate -n --env=test`
  6. `php bin/console doctrine:schema:validate --env=test`  ← **после** миграций
  7. `php bin/phpunit --coverage-clover var/coverage/clover.xml`
  8. `php tools/check-coverage.php` (условный порог)
- Коммит: `ci: run deptrac, schema validate and coverage`.
- `doctrine:schema:validate` при дрейфе → остановка и эскалация (Q5), отдельная
  задача на миграцию; в рамках 001 схему не правим.

### Ш8. Финальная верификация
- `make qa`, `make coverage`, `make console doctrine:schema:validate --env=test`
  (при необходимости сначала `make migrate` и `APP_ENV=test`).
- Проверить отсутствие `phpstan-baseline.neon`, отсутствие секретов в
  `var/coverage/clover.xml` и логах.

### Ожидаемые ошибки PHPStan level 10 и стратегия

Детализация к шагу Ш4.

Полный список идентификаторов фиксируется первым прогоном `make stan` (в плане
приведены категории по результатам чтения кода). Приоритет: **тип/аннотация/
импорт**, `@phpstan-ignore` — последнее средство.

1. **Doctrine-гидратация `Paste::$id`** — `property.unusedType`.
   Идентификатор уже подавлен в коде; привести к формату Q4:
   `@phpstan-ignore property.unusedType (Doctrine assigns id via reflection; review 2026-12-31)`.
   - при `reportUnmatchedIgnoredErrors: true` несовпадение даст отдельную ошибку
     о неиспользованном ignore — проверить первым прогоном.
2. **`mixed` на границе Symfony-контейнера в тесте** —
   `PasteFlowTest::setUp()`: `$container->get(EntityManagerInterface::class)`
   + `self::assertInstanceOf(...)`. Без точной типизации контейнера присвоение
   `mixed` typed-свойству даст ошибку L10. Стратегия:
   - сначала `phpstan-symfony` c `containerXmlPath` должен вывести тип сервиса;
   - fallback — минимальная аннотация
     `/** @var EntityManagerInterface $entityManager */` (без изменения
     поведения).
3. **Doctrine-репозиторий** — `PasteRepository`:
   - `findActiveByToken`: `getOneOrNullResult()` может быть `mixed`, но
     `instanceof Paste` сужает — ожидаемо чисто;
   - `purgeExpired`: `(int) ...->execute()` — кандидат на `UselessCastRule`
     (strict-rules), если PHPStan докажет `int`. Стратегия: если каст реально
     лишний — удалить (возвращаемый тип остаётся `int`), иначе оставить.
4. **strict-rules по всему коду** — булевы условия и строгие сравнения уже
   соблюдены (`if ($form->isSubmitted())`, `null === ...`, все `===`/`!==`);
   ожидаемо чисто. Проверить `in_array`/`empty`/`==`: в коде не встречаются.
5. **`checkUninitializedProperties` в тестах** — `$client`/`$entityManager`
   инициализируются в `setUp()`. Если PHPStan не свяжет жизненный цикл
   PHPUnit-теста и выдаст uninitialized-ошибку — минимальное решение:
   аннотация/инициализация без изменения тестового поведения; при
   необходимости отдельного расширения `phpstan-phpunit` — вернуться на G2
   (вне заявленного списка зависимостей).
6. **`deprecation-rules`** — возможные deprecated-вызовы Symfony/Doctrine в
   легаси (`Schedule`, `Command`, `Repository`). Стратегия: тривиальная замена
   на не-deprecated API; если удаление выходит за объём — точечный
   `@phpstan-ignore` с причиной и датой.
7. **`phpstan-doctrine` mapping/loader** — если появились ложные срабатывания
   на маппинге `Paste` (BLOB/mixed-поля) или на `tests/object-manager.php` —
   точечный ignore с причиной и датой либо fallback без loader’а (см. Ш4).

Ожидаемый объём правок в `src/`:
- `declare(strict_types=1)` — **все 13 файлов** (механически, CS-Fixer);
- global imports/форматирование — ~5–6 файлов;
- собственно типовые правки под L10 — **1–3 файла** (`Paste.php` — ignore,
  возможно `PasteRepository.php`, возможно `PasteFlowTest.php` в tests).
Если объём `@phpstan-ignore` превышает ~5 штук или требует смены сигнатур —
остановка и эскалация (риск «расползания» из `requirements.md`).

## Миграции БД

Нет. Схема и миграции не меняются. `doctrine:schema:validate --env=test` (Q5)
добавляется как проверка соответствия; при выявлении дрейфа — эскалация
человеку и отдельная задача на миграцию, не «немой» фикс схемы.

## Влияние на стандарты (ADR, если нужно)

**ADR не нужен.** Задача реализует уже утверждённые `docs/QUALITY.md`
(§2.1–2.4, §3, §4) и `docs/ARCHITECTURE.md` (границы → deptrac), а также
`docs/CONVENTIONS.md` (strict_types, стиль). Отклонения носят временный и уже
согласованный на G1 характер:

- Q1-A: `deptrac.yaml` описывает as-is, а не целевые модули из ориентира
  `docs/QUALITY.md` §2.2 — прямо утверждено на G1, заменяется при появлении
  `Secret`/`Crypto`/`Shared`.
- Добавление `tests/object-manager.php` и `tools/check-coverage.php` — tooling,
  не архитектурный стандарт.

Гейт **G2 всё равно обязателен** — человек утверждает план и объём правок в
`src/`. Молчаливое изменение стандартов не допускается.

## Риски и обратимые шаги

| Риск | Митигация | Обратимость |
|---|---|---|
| `declare(strict_types=1)` выявляет скрытые `TypeError` | Полный `make test` после Ш2; functional-тест уже покрывает HTTP/формы; правки только типовые | Revert Ш2; каждый шаг отдельным коммитом |
| L10 даёт большой объём ошибок | Сначала типы/аннотации; точечные ignore по Q4; лимит ~5 ignore — эскалация | Правки изолированы; ignore удаляются |
| `reportUnmatchedIgnoredErrors` ломает сборку из-за старого ignore | Актуализировать идентификатор `Paste::$id` и добавить причину/дату | Правка одной строки |
| `phpstan-symfony`/`phpstan-doctrine` требуют контейнер/loader | Прогрев dev-кеша в `make stan`; fallback без `objectManagerLoader`/`containerXmlPath` | Убрать `symfony`/`doctrine` секции |
| `checkUninitializedProperties` ломается на тестах | Аннотация/инициализация; `phpstan-phpunit` — только через G2 | Правка теста/настройки |
| Порог покрытия формален (нет `Domain`/`Application`) | Условный checker: на пустом множестве exit 0; проверка фикстурой (AC10) | Удалить checker, оставить генерацию clover |
| PCOV конфликтует с Xdebug | `XDEBUG_MODE=off` для `make coverage`; PCOV ставится только в dev-стадию | Убрать pcov из Dockerfile |
| `doctrine:schema:validate` выявит дрейф | Запуск после миграций; при дрейфе — остановка и эскалация (Q5) | Отдельная задача/миграция |
| Утечка секретов в артефактах | clover не содержит исходников/значений; `var/coverage` в `.gitignore`; логи без env | Артефакт не коммитится |
| Расширение finder на `config/`/`public/` | Q6-A утверждён; исключения `bundles.php`/`reference.php` сохранены | Revert Ш2 |
| Пакет `qossmic/deptrac` переименован/несовместим | Проверить при `composer require`; при расхождении — эскалация на G2 | Зависимость откатывается |

## Definition of Done для задачи

- [ ] `make qa` зелёный: `cs-check`, `stan` (level 10, 0 ошибок), `deptrac`,
      `test`.
- [ ] `composer qa` запускает `@cs-check`, `@stan`, `@deptrac`, `@test` (AC8).
- [ ] `phpstan.neon` level 10, подключены doctrine/strict-rules/
      deprecation-rules (+ symfony), baseline отсутствует; все ignore — с
      причиной и датой (AC1, AC2, AC13).
- [ ] `.php-cs-fixer.dist.php` содержит целевые правила; `make cs-check` без
      правок; `declare(strict_types=1)` во всех файлах `src/` (AC3, AC4).
- [ ] `make deptrac` без нарушений слоёв (AC6).
- [ ] `make coverage` создаёт непустой `var/coverage/clover.xml`; условный
      порог ≥90% для `src/*/Domain`/`src/*/Application` работает и не падает на
      пустом множестве (AC9, AC10).
- [ ] CI job `test` выполняет cs-check, phpstan, deptrac, migrate,
      schema:validate, phpunit coverage и зелёный; секретов в логах и clover нет
      (AC11, AC12).
- [ ] Diff по `src/`/`tests/` ограничен `declare`/типами/аннотациями/импортами/
      форматированием; публичные API, маршруты и схема БД не изменены (AC5).
- [ ] `docs/UBIQUITOUS_LANGUAGE.md` не изменён (новых терминов нет).
- [ ] План покрыт ревью, артефакты в `docs/task/001-quality-tooling-bootstrap/`.
