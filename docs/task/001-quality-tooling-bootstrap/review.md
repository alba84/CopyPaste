# Ревью: 001-quality-tooling-bootstrap

## Вердикт: approved

Блокирующих замечаний нет. 8 неблокирующих замечаний/предложений (см. ниже).

> Ограничение верификации. В песочнице ревьюера bash ограничен командами
> `git diff/status/log`, терминал IDE и Xdebug-запуск недоступны, контейнеры не
> подняты, а `vendor/` присутствует, но `make qa` выполнить нельзя. Поэтому AC1,
> AC6, AC9 и AC11 проверены **статически** (анализ конфигов, зависимостей,
> исходников фреймворков по Context7/GitHub), а не фактическим прогоном. Ниже
> явно помечено, что именно проверено исполнением, а что — инспекцией. QA-отчёт
> и `notes.md` в `docs/task/001-quality-tooling-bootstrap/` отсутствуют, локальных
> артефактов `var/phpstan` и `var/coverage` на диске нет.

## Соответствие requirements.md

Diff относительно `HEAD` (29 изменённых файлов + `deptrac.yaml`,
`tests/object-manager.php`, `tools/check-coverage.php`) проверен построчно.

| AC | Статус | Обоснование |
|---|---|---|
| AC1 | выполнено (инспекция) | `phpstan.neon:7` — `level: 10`; `:8` — `paths: [src, tests]`; `phpstan-baseline*.neon` отсутствует по всему дереву. |
| AC2 | выполнено (инспекция) | `composer.json:37-39` + `composer.lock`/`installed.json`: `phpstan-doctrine`, `phpstan-strict-rules`, `phpstan-deprecation-rules` установлены; `phpstan.neon:1-5` их `include`-ит. |
| AC3 | выполнено (инспекция) | `.php-cs-fixer.dist.php:14-23` содержит все 7 целевых правил; `global_namespace_import` реально импортирует только классы (константы/функции — `null`/`false`), что совпадает с фактическим diff. `}$output`/`} if` — пре-существующее форматирование и **не** ловится `no_multiple_statements_per_line` (правило требует `;` перед следующим стейтментом), т.е. не является провалом cs-check. |
| AC4 | выполнено (инспекция) | `grep` показал `declare(strict_types=1)` ровно в 13/13 PHP-файлах `src/`; добавлено в `tests/Unit/...`, `tests/bootstrap.php`, `config/bootstrap.php`, `config/preload.php`, `public/index.php`; `config/bundles.php`/`reference.php` исключены finder'ом. |
| AC5 | выполнено | diff по `src/`/`tests/` — только `declare`, импорты, PHPDoc-generics, снятие/добавление `@phpstan-ignore` и FQCN→импорты. Маршруты, сигнатуры публичных методов, схема, криптография, тексты ошибок, ассерты тестов не изменены. |
| AC6 | выполнено (инспекция) | `deptrac.yaml` соответствует схеме deptrac v4 (root `deptrac:`, `classNameRegex`, `ruleset`, `skip_violations: {}`). `skip_violations` пуст — ослабления нет. Все фактические рёбра (`Entity→Repository`, `Controller→Doctrine`, `Dto→Symfony`) разрешены. |
| AC7 | выполнено (инспекция) | `Makefile:41` — `qa: cs-check stan deptrac test`; `.PHONY` полон; падение шага даёт ненулевой код make. |
| AC8 | выполнено (инспекция) | `composer.json:57-61` — `@cs-check`, `@stan`, `@deptrac`, `@test` в порядке QUALITY §3. |
| AC9 | выполнено (инспекция) | `phpunit.xml.dist:17-26` — `<source>` и clover в `var/coverage/clover.xml`; php-code-coverage `Util\Filesystem::write` создаёт каталог рекурсивно, так что отсутствие `var/coverage` на диске не мешает. |
| AC10 | выполнено с оговоркой | `tools/check-coverage.php` условный: нет `src/*/Domain`/`Application` → exit 0 с сообщением; при наличии — порог 90%. Оговорка — Н3. |
| AC11 | выполнено (инспекция) | `.github/workflows/ci.yml:19,23-30`: pcov, cs-fixer dry-run, phpstan, deptrac, migrate → schema:validate → phpunit coverage → check-coverage; порядок корректен. |
| AC12 | выполнено | Новые tooling-скрипты ничего не логируют из env/clover; clover не содержит исходников; `var/*` и `.deptrac.cache` в `.gitignore`. |
| AC13 | выполнено | baseline нет; в `src/` ignore отсутствуют; 3 ignore в тестах имеют причину и дату (`tests/Functional/PasteFlowTest.php:17,19`, `tests/Unit/Service/EncryptionServiceTest.php:13`). |

Отдельно по AC13/AC1: удаление старого
`@phpstan-ignore property.unusedType` у `src/Entity/Paste.php:17` — **корректно**.
`property.unusedType` эмитится `TooWidePropertyTypeRule`, который пропускает
свойство при `ReadWritePropertiesExtension::isAlwaysWritten() === true`; адаптер
`phpstan-doctrine\...\PropertiesExtension` возвращает `true` для generated
identifier. С прежним ignore и `reportUnmatchedIgnoredErrors: true` сборка
падала бы. Подмена критерия не обнаружена.

## Соответствие plan.md

- Решения G1 реализованы: Q1 (минимальный as-is `deptrac.yaml`), Q2 (условный
  порог), Q3 (`pcov` в dev-стадии + `coverage: pcov`), Q4 (ignore с причиной и
  датой), Q5 (`doctrine:schema:validate` после миграций), Q6 (finder без сужения).
- Решения G2: пакет `deptrac/deptrac` (в `composer.json`, `symfony.lock`),
  упоминание в `docs/QUALITY.md:51` синхронизировано; tooling-файлы
  `tools/check-coverage.php` и `tests/object-manager.php` добавлены;
  `phpstan/phpstan-phpunit` не потребовался (narrowing даёт `@phpstan-assert` в
  PHPUnit 11 / типизация `containerXmlPath`).
- Обоснованные отклонения от текста плана:
  - в `deptrac.yaml:23` добавлен слой `Dto: [Symfony]` (в примере плана его не
    было) — необходимо из-за `Assert` в DTO; ослаблением не является;
  - ignore в `Paste` не переоформлен, а снят (см. выше) — это следствие
    `reportUnmatchedIgnoredErrors`, а не пропуск Q4;
  - `Makefile:22` прогревает dev-кеш перед `stan` — как и заложено в плане.
- Правки в `src/` уложились в ~3 файла без смены сигнатур/схемы; критерий
  эскалации (>5 ignore) не достигнут.

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- Архитектурная миграция в `Secret/Crypto/Shared` не выполнялась (out of scope);
  `deptrac.yaml` описывает as-is и прямо санкционирован G1. Молчаливого
  изменения стандарта нет, ADR не требуется.
- `Domain`/`Application`/портов ещё нет — фреймворк в домене отсутствует
  структурно. `declare(strict_types=1)` — во всех файлах (CONVENTIONS §1).
  Суффиксов `Manager`/`Helper`/`Util` не добавлено. Контроллер не потолстел:
  бизнес-логика не переносилась.
- Форматирование обеспечено CS-Fixer, в ревью не обсуждается (CONVENTIONS §9).

## Найденные проблемы

### Блокирующие

Нет.

### Неблокирующие

**Н1. `tools/check-coverage.php` может молча не применять порог.**
`tools/check-coverage.php:16-19` и `:56-59` возвращают exit 0, если каталоги не
найдены или в них 0 statements. Второй случай маскирует рассинхрон префиксов
clover `name` и `$root.'/src/...'` (например, относительные пути) — порог
окажется фиктивным. Рекомендация: отдельной задачей добавить различие
«каталогов нет» и «каталоги есть, но statements = 0» (второе — падать).
Ссылка: AC10, Q2.

**Н2. `composer stan`/`composer qa` не прогревают dev-контейнер.**
`symfony.containerXmlPath` (`phpstan.neon:14`) требует
`var/cache/dev/App_KernelDevDebugContainer.xml`; прогрев делает только
`Makefile:22` (`make stan`), а `composer.json:59` (`stan`) — нет. На «холодном»
checkout `composer qa` может упасть. В CI контейнер создаётся
`composer install`→`cache:clear`, поэтому CI зелёный. Рекомендация: либо
добавить прогрев в composer-скрипт, либо не завязываться на
`containerXmlPath`. Ссылка: QUALITY §3, CONVENTIONS §4.

**Н3. Дрейф документации `docs/QUALITY.md:140`.** Команда coverage в документе
не содержит `-e XDEBUG_MODE=off`, который есть в `Makefile:35`. Рекомендуется
синхронизировать, чтобы документ оставался источником правды.

**Н4. `tests/object-manager.php`: чтение `.env.local` и приведение `$debug`.**
`tests/object-manager.php:12` использует `Dotenv::bootEnv`, который читает
`.env.local`, если он есть (стандартный Symfony-bootstrap, ничего не логирует и
не печатает — утечки нет, но формально критерий «не читает `.env.local`» не
выполнен). `:14-22` для не-`bool` `APP_DEBUG` принудительно ставит `true`, а
Dotenv отдаёт строку — фактически debug всегда `true`. Влияет только на анализ.
Рекомендация: не критично, можно оставить.

**Н5. `setRiskyAllowed(true)` шире необходимого.** `.php-cs-fixer.dist.php:13`
разрешает все risky-правила; фактически risky нужен только для
`declare_strict_types`/`strict_param`. Приемлемо и обосновано, но стоит
зафиксировать причину комментарием или сузить набор в будущем.

**Н6. Пре-существующий плейсхолдер в `docker/php/conf.d/dev.ini:3`.**
`xdebug.mode=${XDEBUG_MODE}` не подставляется PHP ini-парсером (файл не изменялся
задачей). `make coverage` корректно глушит Xdebug env-переменной, поэтому
покрытие снимает PCOV. Не в объёме задачи; рекомендуется отдельный тикет.

**Н7. Пре-существующий флак rate limiter подтверждён как не порождённый
задачей.** Диффа по `PasteController`, лимитам, шаблонам и тестам-сценариям
лимитов нет (изменения в `PasteController` — только `declare`/импорты/FQCN).
Задача не может быть источником нестабильности.

**Н8. Нет локальных артефактов прогона и QA-отчёта.** `var/phpstan`,
`var/coverage` отсутствуют, `docs/task/.../notes.md`/QA-отчёт не созданы;
независимо исполнить гейт в этом окружении не удалось. Это снижает уверенность
проверки AC1/AC6/AC9/AC11, но не является нарушением AC/стандарта. Рекомендация
оркестратору: прогнать `make qa` и `make coverage` в контейнере до G3 (человек
и так запускает `make qa` на G3).

## Протечки абстракций между слоями

Не обнаружено. `deptrac.yaml` не содержит `skip_violations`; внешние слои
`Symfony/Doctrine/Psr/Cron` объявлены явно, чтобы не ссылаться на несуществующие
слои. Рёбра отражают фактическое состояние (`Entity→Repository` из-за
`repositoryClass`, `Controller→Doctrine` из-за `EntityManagerInterface`).
Целевые `Secret/Crypto/Shared` в этой задаче не создаются — их отсутствие не
является нарушением, т.к. миграция out of scope. Обращения к внутренностям чужих
модулей нет (модулей ещё нет).

## Тестовое покрытие

- Новых доменных тестов нет — доменного слоя нет, это соответствует тест-плану.
- `tests/Functional/PasteFlowTest.php` и
  `tests/Unit/Service/EncryptionServiceTest.php` не переписаны под новое
  поведение: только `declare`, импорт `DateTimeImmutable`, замена FQCN и
  `@phpstan-ignore property.uninitialized` (причина + дата). Ассерты и сценарии
  не тронуты.
- Покрытие генерируется по `src`, порог 90% для `src/*/Domain|Application`
  условный (Q2/AC10). Проверка боевым сценарием (создание каталогов, <90%/>90%)
  в плане описана, но в этом окружении не выполнялась (Н8).
- `check-coverage.php` вне `tests/`, постоянным PHPUnit-тестом не покрывается —
  согласовано планом.

## Замечания по безопасности

Неблокирующих замечаний по безопасности, кроме Н4, нет:

- CSRF, rate limiting, TTL и `Cache-Control: no-store` не ослаблены: в
  `PasteController` изменения только `declare`/импорты/FQCN (строки 14-15, 41-42,
  67); лимиты и проверка TTL сохранены.
- Новые tooling-скрипты не логируют `APP_SECRET`, plaintext и производные ключи;
  `tools/check-coverage.php` только читает clover и печатает проценты.
- `var/coverage/` покрыт `/.gitignore:/var/*`, `/.deptrac.cache` добавлен в
  ignore; clover не содержит исходного кода/значений.
- `.env.local` не добавлен в репозиторий; `APP_SECRET` в `.env` — плейсхолдер,
  не секрет.
- `@phpstan-ignore` в тестах обоснованы (PHPUnit lifecycle, не внешняя граница),
  с датой пересмотра — утечек в логи не создают.
- `Dockerfile:12-18` устанавливает `pcov` только в dev-стадии; продуктовые
  стадии и деплой не затронуты. `pecl install xdebug pcov` поддерживает
  несколько пакетов (PEAR install doc: «Installs one or more PEAR packages»).
