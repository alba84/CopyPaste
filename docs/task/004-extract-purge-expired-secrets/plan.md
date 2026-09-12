# План: 004-extract-purge-expired-secrets

> Статус: **G2 пройден.** Решения G1 (Q1–Q6, все вариант A) обязательны. Объём —
> только извлечение сценария удаления истёкших `Secret`; имя/описание команды,
> cron, схема БД и легаси вне purge не меняются. ADR не требуется (следует
> ADR 0002).

## Связь с требованиями

Задача повторяет эталон 002 (`docs/task/002-extract-create-secret/plan.md`,
ADR 0002) и 003 (`docs/task/003-extract-reveal-secret/plan.md`) для сценария
физического удаления истёкших записей: оркестрация уходит в Application,
консольный адаптер становится тонким, SQL и SQLite-специфика остаются в
`Infrastructure`, домен не знает фреймворка (`docs/ARCHITECTURE.md` §3.3, §5,
§6, §9.3). Новых портов, слоёв, схемы БД и терминов не вводится: используется
уже принятый `SecretRepository` (таблица `paste`, DBAL, ADR 0002) и
`Psr\Clock\ClockInterface`.

Решения G1 разворачиваются в плане так:

| G1 | Как реализуется в плане | Шаги |
|---|---|---|
| Q1-A — сохранить `app:paste:purge` и `Delete expired pastes` | `#[AsCommand(name: 'app:paste:purge', description: 'Delete expired pastes')]`; `App\Schedule` не трогается | Ш3, Ш4 |
| Q2-A — `SecretRepository::removeExpiredBefore(DateTimeImmutable): int` | Метод добавляется в порт `Secret/Domain/Repository`; реализация — `DoctrineSecretRepository` (DBAL `DELETE`); `InMemorySecretRepository` дополняется | Ш1, Ш2 |
| Q3-A — SQLite-обслуживание внутри адаптера | `PRAGMA journal_mode=WAL` → `busy_timeout=5000` → `DELETE ... expires_at <= :now` → `incremental_vacuum` только при `count > 0`; дословно как легаси; команда не импортирует Doctrine | Ш1 |
| Q4-A — `PurgeExpiredSecrets` + Handler + `PurgeExpiredSecretsResult(deletedCount)` | Команда без параметров; Handler зависит от порта и `ClockInterface`; результат — типизированный DTO | Ш2 |
| Q5-A — удалить `PurgeExpiredPastesCommand` и `PasteRepository::purgeExpired()` | Удаляются в одном коммите с новым адаптером Presentation; `PasteRepository`, `App\Entity\Paste`, `PasteController::created`, `App\Schedule` остаются | Ш3 |
| Q6-A — unit + integration + console, без роста `@phpstan-ignore` | Три уровня тестов; новые тест-классы не заводят свойств, инициализируемых в `setUp()` — используются локальные переменные/хелперы | Ш1, Ш2, Ш4 |

Соответствие критериям приёмки:

| AC | Где закрывается |
|---|---|
| AC1 | Ш3 (новый `#[AsCommand]` + удаление легаси в одном коммите), Ш4 (console: имя/описание), отсутствие дубля гарантирует удаление легаси |
| AC2 | Ш1 (SQL `expires_at <= :now`, один запрос, `(int)`-счётчик), Ш1/Ш4 (граница `== now` в integration), Ш4 (эффект в БД) |
| AC3 | Ш3 (`writeln(sprintf('Deleted %d expired paste(s).', ...))`, `SUCCESS`), Ш4 (точный вывод, `N = 0`) |
| AC4 | Ш1 (порядок/условие PRAGMA в адаптере), Ш1 (focused maintenance-тест), Ш1/Ш4 (`journal_mode`/`busy_timeout` наблюдаемы) |
| AC5 | Ш2 (оркестрация в Application), Ш3 (тонкая команда), Ш4 (wiring), ревью |
| AC6 | Ш2 (`ClockInterface::now()`), Ш1/Ш2 (FixedClock + запись `now` в дубле) |
| AC7 | Ш1 (SQL только в `Secret/Infrastructure`), AC8 (`make deptrac`) |
| AC8 | Ш1–Ш3 (слои/правила `deptrac.yaml` не меняются), Ш5 (`make deptrac`/`make qa`) |
| AC9 | Ш3 (удалены легаси-команда и `purgeExpired()`; остальное легаси сохранено), существующие тесты без смены ожиданий |
| AC10 | Ш4 (`App\Schedule`: cron `0 * * * *` + `RunCommandMessage('app:paste:purge')`); `App\Schedule` не меняется |
| AC11 | Ш1, Ш2, Ш4 (unit application, integration адаптера, console/functional) |
| AC12 | Ш1–Ш3 (выводится только счётчик; нет логирования payload/ключей), ревью |
| AC13 | Ш5 (`make qa`, `make coverage`, `schema:validate`) |
| AC14 | Ш4 (активные записи сохраняются, истёкшие удаляются), существующие `PasteFlowTest`/`RevealSecretFlowTest`/`CreateSecretFlowTest` без смены ожиданий |

### Замечание о `removeExpiredBefore` как о расширении порта

`SecretRepository` уже реализуют ровно два класса — `DoctrineSecretRepository`
и `tests/Support/Secret/InMemorySecretRepository` (проверено grep). Поэтому
расширение порта не ломает 002/003: добавляется один метод, тест-дубль
дополняется, ожидания существующих тестов не меняются. Отдельный порт
`ExpiredSecretPurger` отклонён на G1 (Q2-B) как лишний адаптер/DI-ради одной
операции.

## Затрагиваемые модули и слои

- **`Secret/Domain`**: расширение порта `SecretRepository` (новый метод).
  VO/агрегат/исключения не меняются.
- **`Secret/Application`**: новый слой use case `PurgeExpiredSecrets` (команда,
  обработчик, результат). Существующие `CreateSecret`/`RevealSecret` не
  трогаются.
- **`Secret/Infrastructure`**: `DoctrineSecretRepository` реализует
  `removeExpiredBefore` (SQL + SQLite-обслуживание). Маппер/`SecretRecord` не
  меняются.
- **`Secret/Presentation/Console`**: новый тонкий
  `PurgeExpiredSecretsCommand` (первый класс модуля в Console).
- **Легаси (вытеснение)**: удаляются `src/Command/PurgeExpiredPastesCommand.php`
  и `PasteRepository::purgeExpired()`. `PasteRepository` (остальные методы),
  `App\Entity\Paste`, `PasteController::created`, `App\Schedule`,
  `SQLitePragmaSubscriber`, шаблоны — не меняются.
- **Конфигурация**: `deptrac.yaml` и `config/services.yaml` **не меняются**
  (см. ниже). `config/packages/*`, `compose*.yaml`, `Makefile` — без правок.
- **Инфраструктура тестов**: `tests/Support/Secret/InMemorySecretRepository.php`,
  `tests/Unit/Secret/Application/PurgeExpiredSecrets/`,
  `tests/Unit/Secret/Infrastructure/Persistence/Doctrine/`,
  `tests/Integration/Secret/Infrastructure/Persistence/Doctrine/`,
  `tests/Functional/PurgeExpiredSecretsCommandTest.php`,
  `tests/Functional/SecretWiringTest.php`.

### Проектируемая структура (изменения относительно 003)

```text
src/
├── Secret/
│   ├── Domain/Repository/SecretRepository.php        # +removeExpiredBefore
│   ├── Application/PurgeExpiredSecrets/              # новый use case
│   │   ├── PurgeExpiredSecrets.php                   # команда без параметров
│   │   ├── PurgeExpiredSecretsHandler.php            # порт + ClockInterface
│   │   └── PurgeExpiredSecretsResult.php             # deletedCount
│   ├── Infrastructure/Persistence/Doctrine/
│   │   └── DoctrineSecretRepository.php              # +removeExpiredBefore (SQL/PRAGMA)
│   └── Presentation/Console/
│       └── PurgeExpiredSecretsCommand.php            # app:paste:purge
├── Command/PurgeExpiredPastesCommand.php             # УДАЛЯЕТСЯ
└── Repository/PasteRepository.php                    # -purgeExpired()
```

### Почему `config/services.yaml` и `deptrac.yaml` не меняются

- `DoctrineSecretRepository` уже алиас `SecretRepository`; `PurgeExpiredSecrets`
  и `PurgeExpiredSecretsHandler` авторегистрируются через `App\:`/`autowire`;
  `Psr\Clock\ClockInterface: '@clock'` уже забиндено. Новая консольная команда
  подхватывается `autoconfigure` по `#[AsCommand]`.
- `Secret_Application` уже имеет право на `Secret_Domain`, `Shared`, `Psr`
  (`Psr\Clock`) и не импортирует Symfony/Doctrine/Sodium.
- `Secret_Presentation` уже имеет право на `Secret_Application`, `Symfony`,
  `Psr`; команда импортирует только Symfony Console и Application-классы.
  `Doctrine\*` в Presentation не появляется (Q3-A).
- `Secret_Infrastructure` уже имеет право на `Doctrine` (`Connection`,
  `Types`). Расширять ruleset не требуется — гейт G2 не нужен.

## Изменение модели/контрактов (агрегаты, VO, порты)

### Порт `SecretRepository` (`Secret/Domain/Repository/SecretRepository.php`)

```php
use DateTimeImmutable;

interface SecretRepository
{
    public function save(Secret $secret): void;

    public function findByToken(Token $token): ?Secret;

    public function tokenExists(Token $token): bool;

    /** Удаляет все Secret с моментом истечения <= $now; возвращает число удалённых. */
    public function removeExpiredBefore(DateTimeImmutable $now): int;
}
```

`DateTimeImmutable` — PHP SPL, разрешён в `Domain` (`Shared`); граница `<=`
(точное равенство = удалить) фиксируется тестами. Порт не знает про
SQL/SQLite/Doctrine.

### Адаптер (`Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepository.php`)

Границы `Infrastructure` (Q3-A): SQL и SQLite-`PRAGMA` остаются здесь, порядок
и условие — дословно как в легаси-команде.

```php
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;

public function removeExpiredBefore(DateTimeImmutable $now): int
{
    $this->connection->executeStatement('PRAGMA journal_mode=WAL');
    $this->connection->executeStatement('PRAGMA busy_timeout=5000');

    $count = (int) $this->connection->executeStatement(
        'DELETE FROM paste WHERE expires_at <= :now',
        ['now' => $now],
        ['now' => Types::DATETIME_IMMUTABLE],
    );

    if ($count > 0) {
        $this->connection->executeStatement('PRAGMA incremental_vacuum');
    }

    return $count;
}
```

- `DBAL 4.4` `executeStatement()` возвращает `int|string`, поэтому явный
  `(int)` (как легаси `(int) ...->execute()`).
- Привязка `Types::DATETIME_IMMUTABLE` даёт тот же SQL-формат `Y-m-d H:i:s`,
  что и легаси QueryBuilder.
- Транзакция не открывается (иначе `PRAGMA journal_mode=WAL` падал бы).
- SQLite-специфичность метода — осознанный паритет с легаси (AC4); смена СУБД
  вне §4.3/объёма 004.

### Application `PurgeExpiredSecrets` (Q4-A)

```php
// PurgeExpiredSecrets.php — команда без параметров
final readonly class PurgeExpiredSecrets
{
}

// PurgeExpiredSecretsResult.php
final readonly class PurgeExpiredSecretsResult
{
    public function __construct(public int $deletedCount)
    {
    }
}

// PurgeExpiredSecretsHandler.php
final readonly class PurgeExpiredSecretsHandler
{
    public function __construct(
        private SecretRepository $secretRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeExpiredSecrets $command): PurgeExpiredSecretsResult
    {
        return new PurgeExpiredSecretsResult(
            $this->secretRepository->removeExpiredBefore($this->clock->now()),
        );
    }
}
```

Момент отсечения берётся только из `ClockInterface` (AC6); SQL/Doctrine/HTTP
внутри нет (AC5/AC7). Отдельного доменного события (`SecretExpired`) не
вводится — нет потребителя (§4.3).

### Presentation Console (`Secret/Presentation/Console/PurgeExpiredSecretsCommand.php`)

```php
#[AsCommand(name: 'app:paste:purge', description: 'Delete expired pastes')]
final class PurgeExpiredSecretsCommand extends Command
{
    public function __construct(private readonly PurgeExpiredSecretsHandler $handler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = ($this->handler)(new PurgeExpiredSecrets());
        $output->writeln(sprintf('Deleted %d expired paste(s).', $result->deletedCount));

        return Command::SUCCESS;
    }
}
```

Команда не импортирует `Doctrine\*`/`Connection`, не считает `now`, не
выполняет PRAGMA (AC5) — только вызывает use case и печатает счётчик.

### Тест-дубль `InMemorySecretRepository` (Q2-A)

Дополняется методом и записью последнего отсечения (детерминированная проверка
AC6):

```php
private ?DateTimeImmutable $lastRemovedBefore = null;

public function removeExpiredBefore(DateTimeImmutable $now): int
{
    $this->lastRemovedBefore = $now;
    $removed = 0;
    foreach ($this->secrets as $token => $secret) {
        if ($secret->expiresAt() <= $now) {
            unset($this->secrets[$token]);
            ++$removed;
        }
    }

    return $removed;
}

public function lastRemovedBefore(): ?DateTimeImmutable
{
    return $this->lastRemovedBefore;
}
```

## Тест-план

Расположение зеркалит `src` (`docs/CONVENTIONS.md` §7). Недетерминизм — только
через порты. Новые тест-классы **не заводят свойств, инициализируемых в
`setUp()`** (помощники возвращают зависимости локально), поэтому
`@phpstan-ignore` не растёт (Q6-A). Мок конкретного класса (`Doctrine\DBAL\Connection`)
допустим только в **Infrastructure**-тесте для проверки порядка/условия PRAGMA;
CONVENTIONS §7 запрещает моки классов лишь в `Domain`/`Application`.

- **unit (application):**
  `tests/Unit/Secret/Application/PurgeExpiredSecrets/PurgeExpiredSecretsHandlerTest.php`
  (`InMemorySecretRepository`, `FixedClock`, без моков):
  - `testRemovesSecretsExpiringAtOrBeforeClockNowAndReturnsCount()` —
    в репозитории записи с `expiresAt` = `now-1s`, `now`, `now+1s`; Handler с
    `FixedClock(now)` → `deletedCount === 2`, осталась только будущая запись
    (AC2, AC6); `lastRemovedBefore() === now` доказывает, что порт вызван с
    `Clock->now()` (AC6).
  - `testReturnsZeroWhenNothingExpired()` — только будущая запись /
    пустой репозиторий → `deletedCount === 0`, ничего не удалено (AC2, AC11).
  - `testDelegatesToRepositoryExactlyOnce()` — счётчик/состояние соответствуют
    одному вызову; повторный экземпляр Handler на тех же часах даёт `0`
    (идемпотентность на уровне use case).
- **unit (infrastructure, focused):**
  `tests/Unit/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepositoryMaintenanceTest.php`
  — `DoctrineSecretRepository` поверх mock `Doctrine\DBAL\Connection`,
  записывающего переданный SQL/params/types:
  - `testRunsSqliteMaintenanceInLegacyOrderAndBindsInclusiveCutoff()` —
    при `DELETE` → `2` последовательность вызовов ровно:
    `PRAGMA journal_mode=WAL`, `PRAGMA busy_timeout=5000`,
    `DELETE FROM paste WHERE expires_at <= :now`, `PRAGMA incremental_vacuum`;
    для `DELETE` связаны `['now' => $now]` и
    `['now' => Types::DATETIME_IMMUTABLE]`; возврат `2` (AC2, AC4).
  - `testSkipsIncrementalVacuumWhenDeleteAffectsNoRows()` — при `DELETE` → `0`
    `PRAGMA incremental_vacuum` не выполняется (AC4).
- **integration:**
  `tests/Integration/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepositoryPurgeTest.php`
  (`KernelTestCase`, реальная SQLite-схема через `SchemaTool`, как в
  `DoctrineSecretRepositoryTest`):
  - `testDeletesSecretsExpiringBeforeOrExactlyAtCutoffAndKeepsActive()` —
    фиксированный `$now`; `expires_at` = `now-1s` / ровно `now` / `now+1s`;
    `removeExpiredBefore($now)` → `2`; первые две строки отсутствуют, будущая
    читается `findByToken` (AC2, AC11).
  - `testReturnsZeroAndKeepsRowsWhenNothingExpired()` — только будущие → `0`,
    строки на месте (AC2).
  - `testSecondRunReturnsZeroAndKeepsActiveSecret()` — повторный вызов → `0`
    (идемпотентность, AC2).
  - `testEnablesWalAndBusyTimeoutOnConnection()` — после purge `fetchOne('PRAGMA
    journal_mode')` = `wal`, `fetchOne('PRAGMA busy_timeout')` = `5000`;
    выполнение не падает на схеме без `auto_vacuum=INCREMENTAL` (AC4).
- **functional (console/schedule):**
  `tests/Functional/PurgeExpiredSecretsCommandTest.php` (`KernelTestCase`,
  `FrameworkBundle\Console\Application` + `CommandTester`, реальная схема; без
  свойств в `setUp()`):
  - `testCommandKeepsLegacyNameAndDescription()` — `find('app:paste:purge')`,
    `getName() === 'app:paste:purge'`,
    `getDescription() === 'Delete expired pastes'` (AC1).
  - `testDeletesExpiredSecretsAndPrintsExactCountAndSucceeds()` — истёкшая
    (`now-1h`) и активная (`now+1h`) строки; вывод ровно
    `"Deleted 1 expired paste(s).\n"`, ``statusCode === Command::SUCCESS``,
    в БД осталась только активная (AC2, AC3, AC14).
  - `testPrintsZeroAndSucceedsWhenNothingExpired()` — вывод ровно
    `"Deleted 0 expired paste(s).\n"`, `SUCCESS` (AC3).
  - `testScheduleStillRunsPurgeCommandHourly()` — `App\Schedule` (получен из
    контейнера) содержит ровно одно recurring-сообщение; trigger — 
    `CronExpressionTrigger`, `(string) $trigger === '0 * * * *'`; провайдер
    отдаёт `RunCommandMessage` с `input === 'app:paste:purge'` (AC10).
- **functional (wiring):**
  `tests/Functional/SecretWiringTest.php` — добавить
  `testContainerExposesPurgeExpiredSecretsHandler()`: контейнер отдаёт
  `PurgeExpiredSecretsHandler` (AC5, AC10-контекст).
- **регрессия:** `PasteFlowTest`, `CreateSecretFlowTest`,
  `RevealSecretFlowTest`, `LegacyInteropTest`, `DoctrineSecretRepositoryTest`
  проходят **без смены ожиданий**; `InMemorySecretRepository` дополняется
  неразрушающим методом (AC9, AC11, AC14).

## Пошаговый план реализации

Каждый шаг — отдельный обратимый коммит; после каждого `make qa`,
`make coverage` и `make console doctrine:schema:validate --env=test` зелёные.
Тесты идут в одном коммите с кодом, чтобы порог `tools/check-coverage.php`
(≥90% для `src/*/{Domain,Application}`) не срабатывал на «полупустых»
каталогах.

- **Ш0. Baseline.** `make up` (если нужно), `make qa`, `make coverage`,
  `make console doctrine:schema:validate --env=test`, `make console list`
  (зафиксировать, что команда `app:paste:purge` есть один раз). Коммита нет.
- **Ш1. Порт + DBAL-адаптер (Q2-A, Q3-A, AC2, AC4, AC6-инфра, AC7).**
  `SecretRepository::removeExpiredBefore`; реализация в
  `DoctrineSecretRepository` (PRAGMA → DELETE → условие vacuum, `(int)`);
  дополнение `InMemorySecretRepository`; focused maintenance-тест и
  integration-тесты границы/идемпотентности/PRAGMA. Консюмеров ещё нет,
  поведение не меняется. Коммит:
  `feat: add expired-secret removal to SecretRepository`.
- **Ш2. Use case (Q4-A, AC5, AC6, AC11).** `PurgeExpiredSecrets`,
  `PurgeExpiredSecretsResult`, `PurgeExpiredSecretsHandler`; application
  unit-тесты. Коммит: `feat: add PurgeExpiredSecrets use case`.
- **Ш3. Presentation switch + вытеснение легаси (Q1-A, Q5-A, AC1, AC3, AC9).**
  Новый `Secret/Presentation/Console/PurgeExpiredSecretsCommand`; **в том же
  коммите** удалить `src/Command/PurgeExpiredPastesCommand.php` и
  `PasteRepository::purgeExpired()` (иначе дубль имени команды). `App\Schedule`,
  `PasteRepository`, `App\Entity\Paste`, `PasteController::created` не трогаются.
  `make console list` — команда ровно одна. Коммит:
  `refactor: purge expired Secrets via use case`.
- **Ш4. Console/functional-тесты (AC1, AC3, AC10, AC14).**
  `PurgeExpiredSecretsCommandTest` (имя/описание/точный вывод/exit code/эффект/
  расписание); дополнение `SecretWiringTest`. Коммит:
  `test: cover purge-expired command contract`.
- **Ш5. Финальная верификация и документы.** `make qa` (cs-check, PHPStan L10 —
  0 ошибок без baseline, deptrac 0, PHPUnit), `make coverage`,
  `doctrine:schema:validate --env=test`; обновить `docs/task/ROADMAP.md`
  (004 → статус/журнал). Коммит:
  `docs: finalize 004 purge-expired-secrets artifacts`.

## Миграции БД

**Нет.** Таблица `paste`, колонки и индекс `idx_paste_expires_at` не меняются;
`removeExpiredBefore` удаляет существующие строки, нового маппинга/схемы нет
(ADR 0002). `config/packages/doctrine.yaml` не меняется, поэтому
`doctrine:schema:validate --env=test` и `SchemaTool` видят тот же маппинг.
Переименование `paste → secret` — задача 005.

## Влияние на стандарты (ADR, если нужно)

**ADR 0004 не нужен.** Задача:

- следует принятому ADR 0002 (DBAL persistence-модель, таблица `paste`,
  целевой набор слоёв deptrac) и не переоткрывает его решений;
- не добавляет и не меняет слои/правила `deptrac.yaml`: используются
  существующие `Secret_Domain`/`Secret_Application`/`Secret_Infrastructure`/
  `Secret_Presentation`, которым уже разрешены нужные зависимости; расширения
  ruleset нет (иначе был бы гейт G2);
- не меняет схему БД, крипто-формат, публичные контракты (имя/описание команды,
  cron, вывод), стандарты качества;
- не вводит новых доменных терминов (`Purge` уже в
  `docs/UBIQUITOUS_LANGUAGE.md`) — глоссарий не меняется;
- не вводит доменных событий/шины (§4.3).

Расширение `SecretRepository` ещё одним методом и перенос SQLite-«обслуживания»
внутрь адаптера — внутренние контракты модуля в рамках §5 и утверждённого
Q2-A/Q3-A, а не изменение архитектурного стандарта. Единственное известное
ограничение (метод SQLite-специфичен) унаследовано от легаси и явно вне объёма
(смена СУБД — §4.3); при появлении второй СУБД потребуется отдельное решение,
но не в 004.

## Риски и обратимые шаги

| Риск | Митигация | Обратимость |
|---|---|---|
| Дублирование имени `app:paste:purge` (легаси + новый класс) | Удаление легаси и добавление команды — в одном коммите Ш3; `make console list`/AC1 проверяют отсутствие дубля | `git revert` Ш3 |
| `incremental_vacuum` не выполняется/выполняется не по условию | Q3-A: перенос дословно в адаптер; focused maintenance-тест проверяет порядок и `count > 0` (AC4) | Правка адаптера |
| SQLite-`PRAGMA` внутри транзакции | Адаптер не открывает транзакцию; порядок как в легаси (AC4) | — |
| Граница `<` вместо `<=` | Integration-тест с `expires_at` ровно `now` (AC2); `isExpiredAt`/легаси используют `<=` | Правка SQL |
| `executeStatement()` возвращает `int\|string` | Явный `(int)`; тест счётчика | Локально |
| Расширение `SecretRepository` ломает тест-дубли 002/003 | `InMemorySecretRepository` дополняется методом; реализации только 2; существующие тесты без смены ожиданий | `git revert` Ш1 |
| Doctrine/SQLite протекают в Presentation | Команда импортирует только Symfony + Application; AC8 (`make deptrac`) | Ревью/`git revert` |
| Второй консюмер `Delete expired pastes` меняет wire/логи | Имя/описание/вывод сохранены; тесты Ш4 сравнивают строку точно | Правка строки |
| Порог покрытия после добавления `Application/PurgeExpiredSecrets` | unit-тесты идут в одном коммите с кодом (Ш2) | Добавить тесты |
| Рост `@phpstan-ignore` в новых тестах | Новые тест-классы без свойств `setUp()`; локальные переменные/хелперы (Q6-A) | Локально |
| Порядок/условие PRAGMA не наблюдаемы на реальной SQLite | Focused Infrastructure-unit-тест с записывающим mock `Connection`; integration проверяет наблюдаемые `journal_mode`/`busy_timeout` | Замена на DBAL logging-middleware, если мок отклонят |
| Смена СУБД сломает `removeExpiredBefore` (SQLite-`PRAGMA`) | Унаследовано от легаси; явно вне §4.3/объёма 004; фиксируется в плане | Отдельная задача |
| Расползание в 005/006 (переименование команды/таблицы, удаление остального легаси, Access) | Явный out of scope; Q1/Q5; `App\Schedule`, `PasteRepository`, `App\Entity\Paste`, `PasteController::created` не трогаются | Ревью AC9/AC10 |
| Утечка данных в вывод/логи | Печатается только число; payload/ключи/token/hint не читаются и не логируются (AC12) | Ревью |

## Definition of Done для задачи

- [ ] Решения Q1–Q6 реализованы как A: имя/описание сохранены, порт расширен,
      SQLite-обслуживание в адаптере, use case + типизированный результат,
      легаси purge вытеснён, три уровня тестов без роста `@phpstan-ignore`.
- [ ] `PurgeExpiredSecrets`/`Result`/`Handler` — в
      `src/Secret/Application/PurgeExpiredSecrets`; порт — в
      `src/Secret/Domain`; SQL/PRAGMA — в `src/Secret/Infrastructure`;
      консольный адаптер — в `src/Secret/Presentation/Console`; Domain/Application
      без фреймворка, команда без Doctrine (AC5, AC7, AC8).
- [ ] Наблюдаемое поведение неизменно: `app:paste:purge`,
      `Delete expired pastes`, cron `0 * * * *`,
      `RunCommandMessage('app:paste:purge')`, `expires_at <= now`, один DELETE,
      точный вывод `Deleted N expired paste(s).`, exit code 0, порядок/условие
      PRAGMA (AC1–AC4, AC10).
- [ ] Момент отсечения берётся из `ClockInterface::now()` (AC6).
- [ ] Легаси `PurgeExpiredPastesCommand` и `PasteRepository::purgeExpired()`
      удалены; `PasteRepository`, `App\Entity\Paste`,
      `PasteController::created`, `App\Schedule` сохранены и работают (AC9).
- [ ] `make qa` зелёный (cs-check, PHPStan L10 — 0 ошибок без baseline,
      deptrac 0, PHPUnit); `make coverage` — `src/Secret/Domain` и
      `src/Secret/Application` ≥ 90%; `doctrine:schema:validate --env=test`
      зелёный; миграций нет (AC13).
- [ ] Purge покрыт: unit application (FixedClock, счётчик, ноль), unit
      infrastructure (порядок/условие PRAGMA), integration (граница `<=`,
      прошлые/будущие, идемпотентность, WAL/busy_timeout), console/functional
      (имя, описание, точный вывод, exit code, эффект, расписание); существующие
      тесты без смены ожиданий (AC11, AC14).
- [ ] `docs/UBIQUITOUS_LANGUAGE.md` не изменён; ADR не требуется;
      `docs/task/ROADMAP.md` актуализирован на этапе реализации.
- [ ] Артефакты задачи — в `docs/task/004-extract-purge-expired-secrets/`.
