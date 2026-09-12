# 001-quality-tooling-bootstrap: Включение целевого QA-набора (PHPStan L10, deptrac, CS-Fixer, coverage)

## Контекст и проблема

Проект CopyPaste имеет **целевой** набор инструментов качества, описанный в
`docs/QUALITY.md` (раздел «Целевой набор»), и опирается на него в
`docs/ARCHITECTURE.md` (границы слоёв → deptrac) и `docs/CONVENTIONS.md`
(`declare(strict_types=1)`, форматирование). Фактически этот набор в репозитории
не включён:

- `phpstan.neon` — уровень 8, нет расширений doctrine/strict-rules/
  deprecation-rules и настроек `treatPhpDocTypesAsCertain`,
  `checkUninitializedProperties`, `reportUnmatchedIgnoredErrors`;
- `.php-cs-fixer.dist.php` — только `@Symfony`;
- `Makefile` — нет `qa`, `cs-check`, `deptrac`, `coverage`;
- `deptrac.yaml` и настройка покрытия отсутствуют; в `composer.json` нет
  QA-скриптов и dev-зависимостей `phpstan-doctrine`, `phpstan-strict-rules`,
  `phpstan-deprecation-rules`, `qossmic/deptrac`;
- во всех 13 PHP-файлах `src/` отсутствует `declare(strict_types=1)`;
- CI (`.github/workflows/ci.yml`) не запускает deptrac, `doctrine:schema:validate`
  и покрытие.

Из-за этого правила, объявленные целевыми, нельзя проверить автоматически:
нарушения типизации и границ слоёв не блокируются, а последующая миграция на
модуль `Secret` окажется «движущейся мишенью». Это первая задача-бутстрап —
включить уже описанный набор и зафиксировать единый инструментальный gate.

## Пользовательская цель

Как мейнтейнер/разработчик CopyPaste, я хочу одной командой `make qa` (и
зелёным CI) получать машинную проверку типов уровня 10, границ слоёв, стиля и
тестов, чтобы:

- новые изменения не ухудшали типизацию и не нарушали границы модулей;
- `declare(strict_types=1)` и целевые правила форматирования соблюдались
  единообразно во всех файлах;
- команда и ИИ-агенты работали по одному воспроизводимому определению качества
  (единый gate), без ручной сверки со `docs/QUALITY.md`.

Ценность — предсказуемость изменений и снижение стоимости последующей
архитектурной миграции.

## Объём (in scope)

1. **PHPStan level 10** в `phpstan.neon` по `docs/QUALITY.md` §2.1 (подключение
   `phpstan-symfony` и новых расширений; `paths: [src, tests]`; ноль ошибок; без
   baseline).
2. **Dev-зависимости**: `phpstan/phpstan-doctrine`,
   `phpstan/phpstan-strict-rules`, `phpstan/phpstan-deprecation-rules`,
   `qossmic/deptrac` — версии, совместимые с PHP 8.4.
3. **Правила PHP-CS-Fixer** по `docs/QUALITY.md` §2.3 (`declare_strict_types`,
   `strict_comparison`, `strict_param`, `void_return`, `ordered_imports`,
   `no_unused_imports`, `global_namespace_import`) поверх текущего `@Symfony`.
4. **`declare(strict_types=1)`** во всех PHP-файлах `src/` (13 файлов); при
   включённом правиле — единообразно во всех файлах, которые охватывает finder
   CS-Fixer.
5. **Минимальные правки в существующем коде** `src/`/`tests/`, необходимые для
   достижения level 10 и strict_types: уточнение типов, аннотации, `@phpstan-*`,
   сигнатуры, импорты. **Без изменения поведения и публичного API** (маршруты,
   сигнатуры публичных методов, схема БД, криптография, тексты ошибок).
6. **deptrac**: `deptrac.yaml` и `make deptrac`; набор слоёв описывает текущее
   состояние и/или целевые модули (см. Q1).
7. **Покрытие**: блок `coverage` в `phpunit.xml.dist` (clover →
   `var/coverage/clover.xml`) и `make coverage`; драйвер PCOV в CI.
8. **`Makefile`**: таргеты `qa`, `cs-check`, `deptrac`, `coverage` по
   `docs/QUALITY.md` §3.
9. **`composer.json` scripts**: `cs-check`, `stan`, `deptrac`, `test`, `qa`.
10. **CI**: в job `test` добавить `deptrac analyse`,
    `doctrine:schema:validate --env=test`, покрытие.
11. **Документы задачи**: этот `requirements.md`; обновление
    `docs/UBIQUITOUS_LANGUAGE.md` — только при реально новых доменных терминах
    (в этой задаче таковых нет, см. ниже).

## Вне объёма (out of scope)

- Перестройка кода под `src/Secret`, `src/Crypto`, `src/Shared` и вообще
  архитектурная миграция — отдельные задачи; здесь только инструменты.
- Любое изменение поведения приложения и публичного API.
- Mutation testing (`infection/infection`) — опционально по
  `docs/QUALITY.md` §2.5 и только после стабилизации структуры.
- Рефакторинг легаси ради стиля, выходящий за необходимый для level 10 и
  strict_types минимум.
- Новые бизнес-функции, миграции БД, изменения криптографии и лимитов.
- Изменение процесса деплоя (остаётся только по тегам `v*.*.*`).

## Доменные термины (новые/уточнённые)

**Новых доменных терминов нет.** Задача процессно-инструментальная. Имена
инструментов (`PHPStan`, `deptrac`, `PHP-CS-Fixer`, `coverage`,
`strict_types`) — технические и по правилу задачи в `docs/UBIQUITOUS_LANGUAGE.md`
не добавляются. Существующие термины не переопределяются; сам файл глоссария не
изменяется.

## Критерии приёмки (Given–When–Then)

**AC1 — PHPStan L10, ноль ошибок.**
Given `phpstan.neon` с `level: 10` и `paths: [src, tests]` и подключёнными
расширениями,
When в чистом окружении выполняется `make stan`,
Then PHPStan завершается с кодом 0 и не сообщает ни одной ошибки; baseline-файл
отсутствует.

**AC2 — Расширения PHPStan installed & включены.**
Given обновлённые `composer.json`/`composer.lock`,
When выполняется `composer install`,
Then установлены `phpstan/phpstan-doctrine`, `phpstan/phpstan-strict-rules`,
`phpstan/phpstan-deprecation-rules`; `phpstan.neon` их включает; `make stan`
остаётся зелёным (AC1).

**AC3 — Целевые правила PHP-CS-Fixer.**
Given `.php-cs-fixer.dist.php`,
When выполняется `make cs-check` (`php-cs-fixer fix --dry-run --diff`),
Then команда завершается с кодом 0 (нет предлагаемых правок), а среди правил
присутствуют `declare_strict_types`, `strict_comparison`, `strict_param`,
`void_return`, `ordered_imports`, `no_unused_imports`,
`global_namespace_import`.

**AC4 — `strict_types` в `src/`.**
Given все PHP-файлы в `src/`,
When проект проверяется сканированием/CS-Fixer,
Then каждый файл содержит `declare(strict_types=1);` первой инструкцией после
`<?php`, а `make test` остаётся зелёным.

**AC5 — Правки не меняют поведение и публичный API.**
Given diff задачи по `src/` и `tests/`,
When его проверяет ревьюер,
Then изменения ограничены типами/аннотациями/импортами/`declare`/
форматированием; публичные маршруты, сигнатуры публичных методов и схема БД не
изменены; тесты не переписаны под новое поведение.

**AC6 — deptrac настроен и зелёный.**
Given `deptrac.yaml`,
When выполняется `make deptrac` (`deptrac analyse`),
Then команда выходит с кодом 0 и не сообщает нарушений правил слоёв/
зависимостей.

**AC7 — Единая команда `make qa`.**
Given `Makefile`,
When выполняется `make qa`,
Then последовательно проходят `cs-check`, `stan`, `deptrac`, `test`; при падении
любого шага итоговый код возврата ненулевой.

**AC8 — `Makefile` и composer-скрипты согласованы.**
Given `Makefile` и `scripts` в `composer.json`,
When выполняется `composer qa`,
Then запускаются `@cs-check`, `@stan`, `@deptrac`, `@test` в соответствии с
`docs/QUALITY.md` §3.

**AC9 — Покрытие генерируется.**
Given coverage-конфигурация в `phpunit.xml.dist`,
When выполняется `make coverage`,
Then создаётся непустой `var/coverage/clover.xml`, а прогон тестов успешен.

**AC10 — Порог покрытия для Domain/Application.**
Given настройка покрытия,
When в `src/*/Domain` и `src/*/Application` появляется код и запускается
coverage,
Then применяется порог **не ниже 90%** к этим каталогам. Пока каталогов нет,
enforcement на пустом множестве не даёт ложного падения (уточняется в Q2).

**AC11 — CI включает deptrac и покрытие.**
Given `.github/workflows/ci.yml`,
When запускается job `test` на PR/push в `main`,
Then он выполняет `php-cs-fixer --dry-run --diff`, `phpstan analyse` (L10),
`deptrac analyse`, `doctrine:schema:validate --env=test` и
`phpunit --coverage-clover var/coverage/clover.xml`, и завершается успешно.

**AC12 — Без утечек секретов.**
Given CI-логи и `var/coverage/clover.xml`,
When проверяется результат,
Then в них нет значений `APP_SECRET`, plaintext, ключей шифрования и содержимого
`.env.local`; артефакты покрытия не содержат секретов.

**AC13 — Zero-error политика PHPStan.**
Given `phpstan.neon` и исходники,
When выполняется `make stan`,
Then нет baseline; любое вынужденное `@phpstan-ignore` точечно, содержит причину
и дату пересмотра (Q4).

## Открытые вопросы (и ответы человека)

> **Гейт G1 пройден.** Требования подтверждены человеком; решения по вопросам
> Q1–Q6 зафиксированы ниже и обязательны для архитектора и разработчика.

### Решения, утверждённые на G1

| Вопрос | Решение |
|---|---|
| Общее подтверждение | `requirements.md` утверждён как основа задачи 001 |
| Q1 — deptrac | **A.** Минимальный `deptrac.yaml` под текущие namespace; замена на целевой при появлении модулей |
| Q2 — порог покрытия | **A.** Генерация clover сейчас; порог ≥90% для `src/*/Domain` и `src/*/Application` вводится условно и начинает действовать при появлении каталогов |
| Q3 — PCOV | **A.** `pcov` в Docker-образе, используется локально (`make coverage`) и в CI |
| Q4 — `@phpstan-ignore` | **A.** Только точечно, с причиной и датой пересмотра; baseline запрещён |
| Q5 — `doctrine:schema:validate` | **A.** Включить в задачу; при дрейфе схемы — эскалация человеку |
| Q6 — область `strict_types` | **A.** Весь проект через finder CS-Fixer (соответствует `docs/CONVENTIONS.md` §1) |

### Исходные вопросы

**Q1. Конфигурация deptrac, пока целевых модулей нет.**
`src/` не содержит `Secret`/`Crypto`/`Shared`; ориентир из `docs/QUALITY.md`
§2.2 на текущем коде покроет лишь `App\Kernel` и оставит большинство классов без
слоя.

- **A (рекомендую).** Минимальный `deptrac.yaml` под текущие пространства имён
  (`Controller`, `Dto`, `Entity`, `Repository`, `Service`, `Command`,
  `EventSubscriber`, `Kernel`) с правилами «как есть»; при появлении модуля
  `Secret` заменить на целевой.
- **B.** Сразу целевой `deptrac.yaml` из `docs/QUALITY.md`; текущие классы
  объявить как uncovered/skip.
- **C.** Отложить deptrac до появления модулей; в этой задаче только поставить
  зависимость и таргет (тогда AC6 недоказуем — не рекомендую).

**Q2. Как включать порог покрытия ≥90% для `src/*/Domain` и
`src/*/Application`, если каталогов ещё нет?**

- **A (рекомендую).** Настроить генерацию clover сейчас; порог вводится
  условно/файлом-конфигурацией и начинает действовать, когда каталоги
  появляются.
- **B.** Ввести общий порог по всему `src/` уже сейчас.
- **C.** В этой задаче только генерировать покрытие, без enforcement; пороги —
  отдельной задачей.

**Q3. Где брать PCOV для `make coverage` (локально и в CI)?**
`make coverage` запускается в контейнере `php`; CI использует
`shivammathur/setup-php`.

- **A (рекомендую).** Добавить `pcov` в PHP-образ, использовать и локально, и в
  CI.
- **B.** Локально Xdebug, PCOV только в CI.
- **C.** Ограничиться покрытием в CI, таргет `make coverage` не добавлять (не
  соответствует постановке — не рекомендую).

**Q4. Политика `@phpstan-ignore` для легаси.**
`docs/QUALITY.md` §2.1 запрещает baseline, но допускает точечный ignore с
причиной и датой.

- **A (рекомендую).** Разрешить точечные `@phpstan-ignore` с причиной и датой
  пересмотра.
- **B.** Запретить ignore — все ошибки L10 исправлять правками типов (может
  заметно расширить объём).
- **C.** Разрешить baseline (противоречит `docs/QUALITY.md` — не рекомендую).

**Q5. Включать ли `doctrine:schema:validate --env=test` в этой задаче?**
Он есть в `docs/QUALITY.md` §4, но не назван в постановке и может выявить дрейф
схемы/миграций, расширив объём.

- **A (рекомендую).** Включить; при обнаружении дрейфа — отдельный пункт объёма
  (миграция) или эскалация.
- **B.** Отложить до отдельной задачи, чтобы не смешивать bootstrap
  инструментов с миграциями.

**Q6. Область применения `declare_strict_types` через CS-Fixer.**
Finder сейчас охватывает весь проект (`config/`, `public/`, `migrations/` и
т.д.), а постановка называет только `src/`.

- **A (рекомендую).** Оставить охват всего проекта — соответствует
  `docs/CONVENTIONS.md` §1 («в каждом PHP-файле»); учесть больший diff.
- **B.** Ограничить finder `src/` и `tests/`, не трогая `config/`/`public/`/
  `migrations/` в этой задаче.

## Нефункциональные требования и риски

### Нефункциональные требования

- **Воспроизводимость.** Все проверки запускаются через Docker/Makefile; команды
  совпадают локально и в CI; версии инструментов зафиксированы в
  `composer.lock`.
- **Производительность.** Покрытие считается только по явному таргету/в CI
  (драйвер PCOV, быстрее Xdebug) и не замедляет обычный `make test`.
- **Безопасность.** Проверки, логи и артефакты не раскрывают секреты, plaintext
  и производные ключи; не ослабляются CSRF, rate limiting, проверки TTL и
  `Cache-Control: no-store`; `.env.local` не попадает в репозиторий/логи.
- **Совместимость.** PHP 8.4, Symfony 7.4; выбранные мажорные версии
  инструментов совместимы с PHP 8.4 и друг с другом.
- **Обозримость diff.** Минимальные правки; CI обязан быть зелёным до merge;
  любой выход за минимальный объём эскалируется.

### Риски

| Риск | Влияние | Митигация |
|---|---|---|
| `declare(strict_types=1)` меняет рантайм-семантику легаси и выявляет скрытые `TypeError` | Возможное изменение поведения | Ограничение объёма: только типовые правки без изменения поведения; полный прогон тестов; ревью AC5 |
| Level 10 даёт большой объём ошибок в старом коде | Расползание объёма задачи | Точечные `@phpstan-ignore` с причиной/датой (Q4); baseline запрещён; при превышении лимита — эскалация человеку |
| Ориентир deptrac не соответствует текущей структуре | Ложные нарушения/пропуски | Q1; при выборе A — пересмотреть конфиг при появлении модулей |
| Каталогов `Domain`/`Application` ещё нет | Порог покрытия формален | Q2 |
| PCOV недоступен локально/в образе | `make coverage` не работает | Q3 |
| `doctrine:schema:validate` выявит дрейф схемы | Смешение bootstrap и миграций | Q5 |
| Расширение finder CS-Fixer на `config/`/`public/`/`migrations/` | Рост diff, риск для bootstrap-файлов | Q6 |
| Изменение поведения незаметно протащено в «типовые» правки | Регрессия | AC5 + review; `make qa` и functional-тесты |
