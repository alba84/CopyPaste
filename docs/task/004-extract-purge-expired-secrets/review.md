# Ревью: 004-extract-purge-expired-secrets

## Вердикт: approved

Проверка независимая, по diff рабочего дерева на `to-ddd`. Все 14 критериев
приёмки выполнены, поведенческий паритет с легаси сохранён, границы слоёв не
нарушены, качественные гейты подтверждены исполнением. Блокирующих замечаний
нет. Рабочее дерево не закоммичено — это ожидаемо перед гейтом G3 (коммит
делает человек).

## Соответствие requirements.md

| AC | Статус | Доказательство |
|---|---|---|
| AC1 — имя/описание/доступность | выполнено | `src/Secret/Presentation/Console/PurgeExpiredSecretsCommand.php:14` — `#[AsCommand(name: 'app:paste:purge', description: 'Delete expired pastes')]`; легаси-класс удалён (git diff); functional `testCommandKeepsLegacyNameAndDescription` зелёный; класс с этим именем ровно один (grep по `#[AsCommand` + `RunCommandMessage`). |
| AC2 — условие `expires_at <= now` | выполнено | `src/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepository.php:70-74` — один `DELETE FROM paste WHERE expires_at <= :now`; integration `testDeletesSecretsExpiringBeforeOrExactlyAtCutoffAndKeepsActive` (граница `== now`), `testReturnsZeroAndKeepsRowsWhenNothingExpired`; unit application с `lastRemovedBefore()`. |
| AC3 — точный вывод и exit 0 | выполнено | Команда `PurgeExpiredSecretsCommand.php:25-27` (`sprintf`, `Command::SUCCESS`); functional `testDeletesExpiredSecretsAndPrintsExactCountAndSucceeds` (`"Deleted 1 expired paste(s).\n"`, `SUCCESS`) и `testPrintsZeroAndSucceedsWhenNothingExpired` (`"Deleted 0 expired paste(s).\n"`). |
| AC4 — SQLite-обслуживание | выполнено | `DoctrineSecretRepository.php:67-78`: `journal_mode=WAL` → `busy_timeout=5000` → `DELETE` → `incremental_vacuum` только при `count > 0`; focused-unit `DoctrineSecretRepositoryMaintenanceTest` проверяет дословный порядок и пропуск vacuum при 0; integration `testEnablesWalAndBusyTimeoutOnConnection` ( `wal`, `5000`) и исполнимый `incremental_vacuum` на схеме без `auto_vacuum=INCREMENTAL` без падения. |
| AC5 — тонкая команда | выполнено | Команда только вызывает `($this->handler)(new PurgeExpiredSecrets())` и печатает `deletedCount`; grep `Doctrine|Connection|PRAGMA` по `src/Secret/Presentation` — 0 совпадений; `now` считается в handler. |
| AC6 — момент отсечения из `Clock` | выполнено | `PurgeExpiredSecretsHandler.php:21` — `removeExpiredBefore($this->clock->now())`; unit-тест `testRemovesSecretsExpiringAtOrBeforeClockNowAndReturnsCount` проверяет `lastRemovedBefore() === now`. |
| AC7 — SQL только в Infrastructure | выполнено | Единственный `DELETE`/`PRAGMA` — в `src/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepository.php`; grep по Application/Domain/Presentation — Doctrine отсутствует. |
| AC8 — deptrac 0 | выполнено исполнением | `make deptrac`: Violations 0, Errors 0, Warnings 0. Ruleset не расширялся (`deptrac.yaml` без правок). |
| AC9 — вытеснение легаси | выполнено | Удалены `src/Command/PurgeExpiredPastesCommand.php` и `PasteRepository::purgeExpired()` (git diff); `PasteRepository`, `App\Entity\Paste`, `App\Schedule`, `PasteController::created`, шаблоны не изменены; functional `testLegacyPurgeCommandAndRepositoryMethodAreGone` зелёный. |
| AC10 — расписание неизменно | выполнено | `src/Schedule.php` не тронут (`0 * * * *` + `RunCommandMessage('app:paste:purge')`); functional `testScheduleStillRunsPurgeCommandHourly` зелёный. |
| AC11 — тестовое покрытие | выполнено | unit application (3), focused-unit infrastructure (2), integration (4), functional (5) + wiring; полный `phpunit`: 139 тестов, 636 assertions, OK. |
| AC12 — безопасность и логи | выполнено | Печатается только число (`PurgeExpiredSecretsCommand.php:25`); `token`/`hint`/`salt`/`nonce`/`ciphertext`/plaintext в SQL и выводе не читаются и не логируются; логирование в новом коде отсутствует; CSRF/rate limiting/`no-store` и схема БД не затронуты. |
| AC13 — качество | выполнено исполнением | `make stan` — 0 errors (L10, без baseline); `make deptrac` — 0; `make cs-check` — 0/100; `make coverage` — 100.00% для `src/*/{Domain,Application}`; `doctrine:schema:validate` — mapping OK, schema in sync; миграций нет. `@phpstan-ignore` — 15, новых нет. |
| AC14 — регрессия активных Secret | выполнено | В functional истёкшая строка удаляется, активная сохраняется; `RevealSecretFlowTest`/`CreateSecretFlowTest`/`PasteFlowTest` проходят без смены ожиданий; маршруты/контракты не менялись. |

REQ Q1–Q6 реализованы как A: имя/описание сохранены, порт расширен
`removeExpiredBefore`, PRAGMA в адаптере, use case + типизированный результат,
легаси purge вытеснён, три уровня тестов без роста ignore.

## Соответствие plan.md

- Структура совпадает с проектируемой (`Secret/Application/PurgeExpiredSecrets`,
  `Secret/Presentation/Console`, расширение порта и адаптера); легаси удалён в
  том же diff, что исключает дубль имени команды.
- Порт `SecretRepository::removeExpiredBefore(DateTimeImmutable): int` —
  ровно как в плане; `InMemorySecretRepository` дополнен неразрушающим методом
  и `lastRemovedBefore()`.
- `config/services.yaml` и `deptrac.yaml` не менялись; автоподстановка
  `Psr\Clock\ClockInterface: '@clock'` и алиас репозитория сработали (wiring-тест
  зелёный).
- Тест-план выполнен полностью, включая `SecretWiringTest` и focused
  maintenance-тест; новые тест-классы не заводят свойств, инициализируемых в
  `setUp()` — ignore не вырос.
- Миграций нет (модель/схема не менялись); ADR не требуется, следует ADR 0002.
- ROADMAP переведён в `[~]`; запись в журнал добавлена.

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- **Направление зависимостей:** Domain → PHP SPL (`DateTimeImmutable`), Application
  → Domain + `Psr\Clock`, Infrastructure → Domain/Doctrine, Presentation →
  Application/Symfony. Совпадает с §3.3 и ADR 0002.
- **Домен:** инварианты и VO не менялись; расширение порта минимально и не
  анемично; ошибки типизированы (ожидаемых бизнес-альтернатив у purge нет).
- **Doctrine/SQLite:** SQL и PRAGMA только в Infrastructure (§5), команда не
  импортирует `Doctrine\*` (§3.3, AC5/AC8).
- **Нейминг:** `PurgeExpiredSecrets`/`...Handler`/`...Result`/`...Command` —
  по CONVENTIONS §2; запрещённых суффиксов нет; `final`/`readonly`, `strict_types`
  соблюдены.
- **Тесты:** зеркалят `src`, Arrange–Act–Assert, без моков VO; мок
  `Doctrine\DBAL\Connection` использован только в Infrastructure-тесте, что
  CONVENTIONS §7 прямо допускает.
- **Security/ошибки:** `Throwable` не глотается (исключения DBAL
  пробрасываются, как в легаси); секреты не логируются.

## Найденные проблемы

### Блокирующие

Нет.

### Неблокирующие

- **Н1 — Порядок фиксации `now` относительно PRAGMA слегка отличается от легаси.**
  `src/Secret/Application/PurgeExpiredSecrets/PurgeExpiredSecretsHandler.php:21`
  берёт `Clock->now()` до входа в адаптер, тогда как легаси считал
  `new DateTimeImmutable()` после `PRAGMA`. Момент отсечения — контракт AC2/AC6,
  а не строгий порядок PRAGMA; при секундной точности формата
  `Y-m-d H:i:s` практического расхождения нет. Замечание информационное,
  исправление не требуется в 004.
- **Н2 — SQLite-специфичность реализации порта.** Обобщённый по имени
  `SecretRepository::removeExpiredBefore`
  (`src/Secret/Domain/Repository/SecretRepository.php:19`) реализован через
  SQLite-`PRAGMA` (`DoctrineSecretRepository.php:67-77`). Это осознанный
  паритет с легаси по Q3-A и ADR 0002; при появлении второй СУБД потребуется
  отдельное решение (вне объёма 004, §4.3 ARCHITECTURE).
- **Н3 — Функциональный тест эффекта опирается на системные часы.**
  `tests/Functional/PurgeExpiredSecretsCommandTest.php:47-48` использует `±1h`
  относительно `now`; запас велик и тест стабилен, но не полностью
  детерминирован. Альтернатива (фиксированные часы через контейнер) — вне
  объёма 004.
- **Н4 — Проверка отсутствия дубля имени команды косвенная.**
  `testCommandKeepsLegacyNameAndDescription` (`...CommandTest.php:32-41`)
  проверяет `has()`/`getName()`/`getDescription()`, но не число команд с таким
  именем. Дубль исключён удалением единственного легаси-класса, поэтому риск
  нулевой; явный ассерт на `list` был бы полезнее. Не блокирует.

## Протечки абстракций между слоями

Протечек нет:

- `Doctrine\DBAL\Connection` и `PRAGMA` — только в `Secret/Infrastructure`
  (`DoctrineSecretRepository.php`), вне Domain/Application/Presentation (grep 0).
- `Domain`/`Application` не импортируют Symfony/Doctrine/Sodium (deptrac 0,
  grep 0).
- `Presentation/Console` импортирует только Symfony Console и Application-классы
  (`PurgeExpiredSecretsCommand.php:7-12`).
- Domain-порт отдаёт примитив `int` и принимает PHP SPL-тип; ORM-сущность
  `App\Entity\Paste` наружу из нового модуля не выходит.

## Тестовое покрытие

- **Application (unit, in-memory + FixedClock):** граница `<=` (past/now/future),
  возврат счётчика, нулевой случай, вызов порта с `Clock->now()`,
  идемпотентность. Моков VO и классов нет.
- **Infrastructure (focused unit):** дословный порядок SQL-
  обслуживания и пропуск `incremental_vacuum` при `count = 0`; привязка
  `['now' => $now]` и `Types::DATETIME_IMMUTABLE`.
- **Infrastructure (integration):** реальная SQLite-схема, границы
  `< now` / `== now` / `> now`, повторный запуск → 0, наблюдаемые
  `journal_mode=wal` и `busy_timeout=5000`.
- **Functional (console):** имя, описание, точный вывод с `\n`, exit code,
  фактическое удаление/сохранение, отсутствие легаси, расписание.
- **Wiring:** контейнер отдаёт `PurgeExpiredSecretsHandler`.
- **Регрессия:** существующие тесты проходят без смены ожиданий (139/139).
- Покрытие `src/*/{Domain,Application}` — 100% (порог 90%).
- `@phpstan-ignore` — 15 (было 15 в 003), новых нет; все — обоснованные
  `property.uninitialized` в тестах с причиной и датой.

## Замечания по безопасности

- Наружу (stdout) и в логи попадает только количество удалённых; `token`, `hint`,
  `salt`/`nonce`/`ciphertext`, plaintext и ключи не читаются, не печатаются и не
  логируются (AC12).
- `DELETE` выполняется без `SELECT` содержимого; батчинг/лимиты не добавлены,
  поведение не изменено.
- CSRF, rate limiting, `Cache-Control: no-store`, проверка срока и схема БД не
  затрагиваются; `.env.local` не менялся; миграций нет.
- `PRAGMA journal_mode=WAL`/`busy_timeout`/`incremental_vacuum` выполняются без
  открытия транзакции — как в легаси, падения внутри транзакции исключены.

## Верификация (исполнение)

| Команда | Результат |
|---|---|
| `make stan` (PHPStan L10) | OK, 0 errors |
| `make deptrac` | Violations 0, Errors 0, Warnings 0 |
| `make cs-check` | 0 of 100 files can be fixed |
| `make test` / `make coverage` | OK (139 tests, 636 assertions); coverage 100.00% |
| `doctrine:schema:validate --env=test` | mapping OK, schema in sync |

## Рекомендация к G3

Diff соответствует объёму 004 и не выходит за него. Счётчик `@phpstan-ignore`
не вырос (15), схема и публичные контракты не менялись. Готово к коммиту
человеком после визуальной проверки diff.
