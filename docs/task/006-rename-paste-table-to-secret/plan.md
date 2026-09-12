# План: 006-rename-paste-table-to-secret (005b)

> Статус: **G2 пройден.** G1: Q1-A (anchor оставить и переименовать), Q2-B
> (`secret`-имена индексов), Q3-A (публичные имена не трогать), Q4-A (тест
> up/down на данных), Q5-A (ADR 0004), Q6-A (историю не редактировать). Объём —
> отдельная обратимая миграция `paste → secret` с сохранением данных и
> наблюдаемого поведения. ADR 0004 утверждён (`accepted`).

## Связь с требованиями

Задача закрывает последний разрыв между доменным словарём и физической схемой:
после 005a код живёт в модуле `Secret`, но таблица и технические имена всё ещё
называются `paste`. 006 переименовывает таблицу одной новой миграцией, приводит
имена индексов к целевым и синхронизирует anchor/адаптер/тесты. Публичные имена
(маршруты, команда, wire-формы, шаблоны, docker-том) сознательно не трогаются
(Q3-A). Новых доменных терминов нет — `docs/UBIQUITOUS_LANGUAGE.md` не меняется.

Решения G1 разворачиваются так:

| G1 | Как реализуется в плане | Шаги |
|---|---|---|
| Q1-A — anchor оставить, переименовать | `PasteSchemaAnchor` → `SecretSchemaAnchor` (`#[ORM\Table(name:'secret')]`), миграция переименовывает таблицу; DBAL-адаптер остаётся | Ш1 |
| Q2-B — `secret`-имена индексов | `DROP INDEX` + `CREATE`: `uniq_secret_token`, `idx_secret_expires_at`; явные имена в ORM-атрибутах | Ш1 |
| Q3-A — публичные имена не трогать | `app:paste:purge`, `app_paste_*`, `create_paste`/`unlock_paste`, `templates/paste/*`, `#paste-url`, том `paste_data` — без изменений | весь план (out of scope) |
| Q4-A — тест up/down на данных | новый `RenamePasteToSecretMigrationTest` на изолированной in-memory SQLite через Doctrine Migrations API | Ш2 |
| Q5-A — ADR 0004 | `docs/adr/0004-rename-paste-table-to-secret.md` (`proposed`), дополняет ADR 0002/0003 | Ш0/Ш3 |
| Q6-A — историю не редактировать | `Version20260909000000` остаётся как есть; переименование — `Version20260910000000` | Ш1 |

Соответствие критериям приёмки:

| AC | Где закрывается |
|---|---|
| AC1 — чистая БД даёт `secret` | Ш1 (миграция), CI `migrate → schema:validate`; проверяется Ш2/Ш3 |
| AC2 — сохранение данных | Ш2 `RenamePasteToSecretMigrationTest` (строки, NUL, nullable hint, дырки id, AUTOINCREMENT) |
| AC3 — индексы согласованы с ORM | Ш1 (`SecretSchemaAnchor` с явными именами) + `SecretSchemaAnchorTest` (`schemaInSyncWithMetadata`) + CI `schema:validate`; фактическое `PRAGMA index_list` — в Ш2 |
| AC4 — обратимость | Ш2 (up → down → повторный up) |
| AC5 — prod-код использует `secret` | Ш1 `DoctrineSecretRepository` (4 SQL), `SecretSchemaAnchor` (таблица/индексы); `SecretRecordMapper`/колонки без правок |
| AC6 — тесты синхронизированы | Ш1 (все литералы `paste`-таблицы → `secret`, тест anchor переименован) + Ш2 (тест миграции) |
| AC7 — поведение и публичные имена неизменны | Ш1/Ш3 (существующие functional/wiring-тесты без смены ожиданий; Q3-A) |
| AC8 — качество и CI | Ш0/Ш4 (`make qa`, `make coverage`, `schema:validate`); без новых `@phpstan-ignore` |
| AC9 — безопасность миграции | Ш1 (rename без пересоздания/`DROP TABLE`, транзакция), Ш2 (миграция не логирует payload) |
| AC10 — документы и ADR | Ш0 (ADR 0004 `proposed`), Ш3 (ADR `accepted`, `ARCHITECTURE.md` §5, `ROADMAP.md`) |

## Затрагиваемые модули и слои

Всё изменение локализовано в `Secret/Infrastructure/Persistence/Doctrine` и
`migrations/`; `Secret/Domain` и `Secret/Application` не трогаются.

| Место | Изменение |
|---|---|
| `migrations/Version20260910000000.php` | **новый**: `up`/`down` rename таблицы и индексов |
| `src/Secret/Infrastructure/Persistence/Doctrine/Schema/PasteSchemaAnchor.php` | переименован в `SecretSchemaAnchor.php`; таблица `secret`; явные имена индексов |
| `src/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepository.php` | 4 SQL-литерала `paste` → `secret` (insert, findByToken, tokenExists, DELETE) |
| `src/Secret/Infrastructure/Persistence/Doctrine/SecretRecordMapper.php`, `SecretRecord.php` | **без изменений** (колонки не меняются) |
| `config/packages/doctrine.yaml` | **без изменений** (mapping `SecretSchema` → каталог `Schema/`; меняется только имя класса внутри) |
| `config/services.yaml` | **без изменений** (каталог `Schema/` уже исключён из авторегистрации) |
| `deptrac.yaml` | **без изменений** (правила по namespace, не по классу) |
| `tests/Integration/.../PasteSchemaAnchorTest.php` | переименован в `SecretSchemaAnchorTest.php`; таблица/индексы/`PRAGMA` → `secret` |
| `tests/Integration/.../DoctrineSecretRepositoryTest.php` | `FROM/insert('paste')` → `secret` |
| `tests/Unit/.../DoctrineSecretRepositoryMaintenanceTest.php` | ожидаемые SQL `DELETE FROM secret` |
| `tests/Functional/{CreateSecretFlowTest,RevealSecretFlowTest}.php` | `SELECT COUNT(*) FROM secret` |
| `tests/Integration/Migrations/RenamePasteToSecretMigrationTest.php` | **новый** тест миграции |
| `docs/adr/0004-*.md`, `docs/ARCHITECTURE.md` §5, `docs/task/ROADMAP.md` | документы (Q5-A/Q6-A) |
| Публичные имена (`app_paste_*`, `app:paste:purge`, формы, `templates/paste/*`, `#paste-url`, том `paste_data`) | **не трогаются** (Q3-A) |

### Почему код и миграция — один коммит

`make test` создаёт схему через `SchemaTool` по anchor, а CI применяет миграции и
затем `doctrine:schema:validate`. Если переименовать anchor/адаптер без миграции
или миграцию без кода, один из гейтов становится красным (`SchemaTool` создаст
`secret`, а миграции — `paste`; или наоборот). Поэтому изменение таблицы,
класса-якоря, SQL адаптера и существующих тестов входит в **один атомарный
коммит** Ш1. Тест миграции (Ш2) и документы (Ш3) — отдельные аддитивные шаги.

## Изменение модели/контрактов (агрегаты, VO, порты)

**Доменная модель, VO, события и порты не меняются.** `SecretRepository` не
затрагивается, новых методов нет. Меняется только техническое представление
персистентности (имя таблицы/класса-якоря и имена индексов).

### ORM schema-anchor (`SecretSchemaAnchor`)

```php
namespace App\Secret\Infrastructure\Persistence\Doctrine\Schema;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'secret')]
#[ORM\UniqueConstraint(name: 'uniq_secret_token', columns: ['token'])]
#[ORM\Index(name: 'idx_secret_expires_at', columns: ['expires_at'])]
class SecretSchemaAnchor
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    // unique снят с колонки: unique-индекс задаётся явно на уровне класса
    // (иначе Doctrine сгенерирует второй, авто-хешированный индекс).
    #[ORM\Column(length: 64)]
    public string $token = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $hint = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $salt = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $nonce = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $ciphertext = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $expiresAt = null;
}
```

- Класс остаётся **non-final** (урок B1 из 005: `final` ORM-класс ломает
  `ProxyCacheWarmer`/`cache:warmup` в prod). Публичные свойства — как в 005.
- `#[ORM\UniqueConstraint(name: 'uniq_secret_token', ...)]` +
  `#[ORM\Index(name: 'idx_secret_expires_at', ...)]` — явное закрепление имён,
  снимающее ловушку авто-хеша `UNIQ_<hash(table,token)>` навсегда.
- `SecretRecordMapper`/`SecretRecord` не меняются: колонки те же.

## Тест-план

Расположение зеркалит `src` (`docs/CONVENTIONS.md` §7). Domain/Application не
меняются — их 100% покрытие сохраняется.

- **unit:**
  - `tests/Unit/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepositoryMaintenanceTest.php`:
    обновить ожидаемые SQL-строки `DELETE FROM secret WHERE expires_at <= :now`
    (2 места). Логика/мок `Connection` без изменений.
  - `SecretRecordMapperTest` не меняется.
- **application:** без изменений (порты/домен не затронуты).
- **integration:**
  - `DoctrineSecretRepositoryTest`: `SELECT ... FROM secret`, `insert('secret', ...)`.
  - `DoctrineSecretRepositoryPurgeTest`: без изменений (нет литерала таблицы).
  - `PasteSchemaAnchorTest` → `SecretSchemaAnchorTest`:
    `getTableName() === 'secret'`; `getTable('secret')`; `hasIndex('uniq_secret_token')`
    (unique, `token`) и `hasIndex('idx_secret_expires_at')` (`expires_at`);
    `schemaInSyncWithMetadata()` = true и пустой `getUpdateSchemaSql()`;
    `schema_assets_filter` → `'secret' === $asset`; `PRAGMA table_info(secret)`.
  - **новый** `tests/Integration/Migrations/RenamePasteToSecretMigrationTest.php`
    (kernel-less, изолированная in-memory SQLite; см. ниже) — AC1–AC4.
- **functional:**
  - `CreateSecretFlowTest::countStoredSecrets` → `SELECT COUNT(*) FROM secret`.
  - `RevealSecretFlowTest::countStoredSecrets` → `SELECT COUNT(*) FROM secret`.
  - `PasteFlowTest`, `SecretCreatedFlowTest`, `SecretWiringTest`,
    `PurgeExpiredSecretsCommandTest`, `LegacyRemovalTest` — **без изменений**:
    проверяют публичные имена/поведение (Q3-A) и не обращаются к таблице.

### Дизайн `RenamePasteToSecretMigrationTest`

Цель — прогнать **реальную** миграцию на БД с данными, изолированно от общего
test-контейнера:

- **БД:** `Doctrine\DBAL\DriverManager::getConnection(['url' => 'sqlite:///:memory:'])`.
  In-memory PDO/SQLite даёт полную изоляцию на тест; подтверждено `DsnParser`
  DBAL 4 (`sqlite:///:memory:` → `memory = true`).
- **Runner:** собрать `Doctrine\Migrations\DependencyFactory` вручную на этом
  соединении — без контейнера и без правок composer autoload:
  ```php
  $configuration = new Configuration();
  $configuration->addMigrationsDirectory('DoctrineMigrations', dirname(__DIR__, 3).'/migrations');
  $configuration->setAllOrNothing(false);
  $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());

  $factory = DependencyFactory::fromConnection(
      new ExistingConfiguration($configuration),
      new ExistingConnection($connection),
  );
  $factory->getMetadataStorage()->ensureInitialized();
  $factory->getMigrator()->migrate(
      $factory->getMigrationPlanCalculator()->getPlanForVersions(
          [new Version('DoctrineMigrations\\Version20260910000000')],
          Direction::UP,
      ),
      new MigratorConfiguration(),
  );
  ```
  `FilesystemMigrationsRepository` сам `require_once`-ит файлы миграций
  (`Finder::loadMigrations`), поэтому классы `DoctrineMigrations\Version...`
  не обязаны быть в composer autoload; в тесте они адресуются строками версий,
  а не статическими ссылками (важно для PHPStan L10).
- **Сценарий теста (один `Connection`, последовательность):**
  1. `ensureInitialized()`; миграция `Version20260909000000` **up** — создаётся
     `paste` с legacy-индексами `UNIQ_9C5678985F37A13B` и `idx_paste_expires_at`.
  2. Seed ≥3 строк с явными `id` (дырки, напр. 1/5/9), `hint = null` и
     `hint <> null`, payload с нулевыми байтами; `Types::BLOB`.
  3. Миграция `Version20260910000000` **up**.
  4. Assertions AC2/AC3:
     - `sqlite_master` не содержит таблицу `paste`;
     - число строк в `secret` не изменилось; `id/token/hint/created_at/expires_at`
       и `hex(salt/nonce/ciphertext)` совпадают построчно (nullable hint — `null`);
     - `PRAGMA index_list(secret)`: ровно `uniq_secret_token` (unique=1) и
       `idx_secret_expires_at`; `PRAGMA index_info` даёт `token`/`expires_at`;
     - `SELECT name FROM sqlite_sequence` = `secret`;
     - новая вставка без `id` получает `id` > прежнего максимума (AUTOINCREMENT
       не сброшен; SQLite обновляет `sqlite_sequence` в
       `sqlite3AlterRenameTable` — проверено по исходникам).
  5. **down** новой миграции; assert `paste` с теми же строками и индексами
     `UNIQ_9C5678985F37A13B` / `idx_paste_expires_at`; `secret` отсутствует.
  6. Повторный **up**; assert `secret` и сохранность строк (AC4).
  7. Guard: `SELECT sqlite_version()` ≥ `3.25.0` (`version_compare`), иначе
     `ALTER TABLE ... RENAME` семантика 3.25+ не гарантирована.
- Тест не использует `KernelTestCase`/контейнер и не пишет в `var/data/test.db`,
  поэтому не конфликтует с `SchemaTool`-bootstrap остальных тестов.

## Пошаговый план реализации

Каждый шаг — отдельный обратимый коммит; после каждого `make qa`,
`make coverage` и `make console doctrine:schema:validate --env=test` зелёные.
Тест-файлы, зависящие от изменения, идут в том же коммите, что и код.

- **Ш0. Baseline и предпосылки (без коммита).**
  `make up`, `make qa`, `make coverage`,
  `make console doctrine:schema:validate --env=test`; зафиксировать число
  `@phpstan-ignore` и покрытие. Зафиксировать `SELECT sqlite_version()` в dev
  (контейнер) и в CI-окружении; подтвердить ≥ 3.25.0. Сверить фактические имена
  legacy-индексов (`PRAGMA index_list(paste)` на dev-БД либо из
  `Version20260909000000`): `UNIQ_9C5678985F37A13B`, `idx_paste_expires_at`.
  ADR 0004 создан архитектором (`proposed`, вне коммита кода).

- **Ш1. Атомарное переименование схемы (миграция + код + существующие тесты).**
  Добавить `migrations/Version20260910000000.php`; переименовать
  `PasteSchemaAnchor` → `SecretSchemaAnchor` (таблица `secret`, явные имена
  индексов); обновить 4 SQL в `DoctrineSecretRepository`; переименовать
  `PasteSchemaAnchorTest` → `SecretSchemaAnchorTest`; обновить литералы таблицы
  в `DoctrineSecretRepositoryTest`, `DoctrineSecretRepositoryMaintenanceTest`,
  `CreateSecretFlowTest`, `RevealSecretFlowTest`. Коммит:
  `feat: rename paste table to secret with explicit indexes`.

- **Ш2. Тест миграции на данных.**
  Добавить `tests/Integration/Migrations/RenamePasteToSecretMigrationTest.php`
  (up/down/up, данные, индексы, `sqlite_sequence`, версия SQLite). Коммит:
  `test: cover paste-to-secret migration data preservation`.

- **Ш3. Документы.**
  Перевести ADR 0004 в `accepted` (после G2), обновить `docs/ARCHITECTURE.md` §5
  (`secret`, `SecretSchemaAnchor`, политика явных имён индексов),
  `docs/task/ROADMAP.md` (006). `docs/UBIQUITOUS_LANGUAGE.md` не меняется.
  Коммит: `docs: record paste-to-secret rename and index naming policy`.

- **Ш4. Финальная верификация (без коммита).**
  `make qa` (cs-check, PHPStan L10 — 0 без baseline, deptrac 0, PHPUnit),
  `make coverage` (Domain/Application ≥ 90%), `schema:validate` — зелёные;
  сверить, что публичные имена и `migrations/Version20260909000000` не изменены.
  Передать на G3 человеку.

## Миграции БД

### Новая миграция `migrations/Version20260910000000.php`

`Version20260910000000` лексикографически больше `20260909000000`; историческая
миграция **не редактируется** (Q6-A). SQL — SQLite-специфичный, как и
существующая миграция.

```php
final class Version20260910000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename paste table to secret and align index names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paste RENAME TO secret');
        $this->addSql('DROP INDEX UNIQ_9C5678985F37A13B');
        $this->addSql('DROP INDEX idx_paste_expires_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_secret_token ON secret (token)');
        $this->addSql('CREATE INDEX idx_secret_expires_at ON secret (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_secret_token');
        $this->addSql('DROP INDEX idx_secret_expires_at');
        $this->addSql('ALTER TABLE secret RENAME TO paste');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9C5678985F37A13B ON paste (token)');
        $this->addSql('CREATE INDEX idx_paste_expires_at ON paste (expires_at)');
    }
}
```

Факты, на которые опирается миграция (проверено по SQLite и коду проекта):

- **`ALTER TABLE ... RENAME` — метаданные, данные не переписываются**; индексы и
  триггеры остаются прикреплёнными, но их **имена не меняются** (SQLite lang
  `altertable`, §2). Отсюда обязательный `DROP INDEX` + `CREATE INDEX`.
- **`sqlite_sequence` обновляется автоматически**: `sqlite3AlterRenameTable`
  выполняет `UPDATE sqlite_sequence SET name = <new> WHERE name = <old>`
  (SQLite `alter.c`). Поэтому миграция **не** трогает `sqlite_sequence` вручную;
  непрерывность `AUTOINCREMENT` проверяется тестом AC2. Ручной `UPDATE` был бы
  лишним и рискованным.
- **`sqlite_autoindex_*`** (индексы inline `UNIQUE`) SQLite переименовывает сам,
  но здесь unique-индекс создан явным `CREATE UNIQUE INDEX`, поэтому имя
  `UNIQ_9C5678985F37A13B` не меняется и должно быть удалено явно.
- **Транзакционность:** `AbstractMigration::isTransactional()` = true; SQLite
  выполняет DDL в транзакции, поэтому при ошибке rename/indexes откатываются
  целиком (AC9). Миграция не делает `DROP TABLE` и не пересоздаёт данные.
- **Идемпотентность:** на уровне SQL полной идемпотентности нет (у `ALTER TABLE
  ... RENAME` нет `IF EXISTS`); её обеспечивает таблица версий Doctrine
  Migrations. Обратимость (`down`, затем снова `up`) закрыта тестом AC4.
- **Фактические имена индексов** до миграции детерминированы исторической
  миграцией: `UNIQ_9C5678985F37A13B` (авто-хеш Doctrine от `paste`+`token`),
  `idx_paste_expires_at`; тест сверяет их через `PRAGMA index_list`.

### Влияние на существующие среды/пути

- **AC1 (чистая БД, CI):** `doctrine:migrations:migrate -n --env=test` выполняет
  обе миграции; `doctrine:schema:validate --env=test` сравнивает `secret` +
  `uniq_secret_token` + `idx_secret_expires_at` с anchor → `mapping OK`,
  `schema in sync`, exit 0.
- **`make test`:** `SchemaTool` создаёт `secret` из anchor — совпадает с CI по
  имени таблицы и индексов.
- **Prod (`deploy/deploy.sh`):** бэкап `app.db` → `doctrine:migrations:migrate`
  на живой БД → старт. Миграция — метаданные + разовое перестроение
  unique-индекса `token` (O(n), в транзакции), строки не переписываются.
- **`config/packages/doctrine.yaml`, `Makefile`, `.github/workflows/ci.yml`,
  `compose*.yaml`, `deploy.sh`** — **без изменений** в 006.
- **Известный несмешанный сценарий:** БД, созданная только `SchemaTool` (таблица
  `secret`, пустая таблица версий), при `migrations:migrate` попытается создать
  `paste` и затем переименовать в занятый `secret`. Это не поддерживаемый путь
  (prod — только миграции; CI — fresh DB). Риск зафиксирован ниже.

## Влияние на стандарты (ADR, если нужно)

**Нужен ADR 0004** (приложен, `proposed`): «Переименование `paste → secret` и
политика явных имён индексов». Он дополняет ADR 0002/0003 и не меняет
quality-гейт (`schema:validate` остаётся). Это изменение стандарта
`docs/ARCHITECTURE.md` §5 (там таблица всё ещё названа `paste`), поэтому перед
реализацией требуется **гейт G2 с решением человека**.

После `accepted` обновляются:

- `docs/ARCHITECTURE.md` §5 — `paste` → `secret`, имя якоря
  `SecretSchemaAnchor`, явные имена индексов `uniq_secret_token` /
  `idx_secret_expires_at`.
- `docs/adr/0003-paste-schema-anchor.md` — пометка, что anchor переименован, а
  политика имён индексов зафиксирована ADR 0004 (историю решения не переписываем,
  добавляем ссылку/актуализацию).
- `docs/adr/0002-secret-persistence-and-deptrac.md` — ссылка на ADR 0004 в части
  «005 переименует таблицу» → «006 переименовала».
- `docs/task/ROADMAP.md` — статус 006.

**Migrations-only (Q1-B) сознательно откладывается** отдельной задачей: 006 не
меняет `QUALITY §4` и bootstrap тестов (Q1-A). `docs/UBIQUITOUS_LANGUAGE.md` не
меняется (новых терминов нет).

## Риски и обратимые шаги

| Риск | Влияние | Мера | Обратимость |
|---|---|---|---|
| Потеря/искажение строк при rename | Утрата Secret | `ALTER TABLE ... RENAME` не переписывает строки; AC2 (NUL, nullable hint, дырки id); бэкап `deploy.sh` | `down` миграции / restore бэкапа |
| `sqlite_sequence` не обновлён → коллизия `id` | Ошибка вставки/перезапись | SQLite обновляет sequence сам (`alter.c`); AC2 проверяет `id` новых вставок | `down` |
| unique-имя меняется вместе с таблицей | `schema:validate` красный | Явные имена в anchor и миграции; `DROP`+`CREATE`; AC3 | Правка anchor/миграции |
| Перестроение unique-индекса дорого на большой БД | Долгая миграция/блокировка | Разово и транзакционно; Q2-A как альтернатива без перестроения | `down` (обратное перестроение) |
| **Откат релиза после применённой миграции** | Старый образ ищет `paste`, БД — `secret` | `deploy.sh` бэкапит до миграции, но `down()` автоматически не вызывает; задокументировать ручной `down`/restore; рекомендация — доработать rollback в ops-задаче | restore бэкапа + `down` вручную |
| SQLite < 3.25 | Rename недоступен | Guard-тест `sqlite_version >= 3.25.0`; фиксация версии на Ш0 | Блокер до реализации |
| Расхождение `make test` (SchemaTool) и CI (миграции) | Ложно-зелёные/красные | AC1 (миграции) + AC3/anchor-тест + единые имена индексов | — |
| Смешанный bootstrap-сценарий (SchemaTool-`secret` + пустая история миграций) | Ошибка `migrate` | Не поддерживаемый путь; CI — fresh DB, prod — только миграции; зафиксировано | Пересоздать БД/историю |
| Ошибочное переименование публичных имён/тома | Ломает wire/ops | Q3-A; out of scope; AC7; существующие wiring/functional-тесты | `git revert` |
| Снижение покрытия/рост `@phpstan-ignore` | Ослабление гейта | `make qa`/`make coverage`; тест без свойств `setUp()`; ignore только с причиной/датой | Локально |

## Definition of Done для задачи

- [ ] `Version20260910000000` применяется с нуля (AC1) и на БД с данными (AC2),
      обратима (AC4); `doctrine:schema:validate --env=test` — mapping OK,
      schema in sync, exit 0 (AC3).
- [ ] `up`: `ALTER TABLE paste RENAME TO secret` +
      `DROP UNIQ_9C5678985F37A13B`/`idx_paste_expires_at` +
      `CREATE uniq_secret_token`/`idx_secret_expires_at`; `down` возвращает
      точную предыдущую схему; `Version20260909000000` не изменён (Q6-A).
- [ ] `src/` не содержит SQL/классов с таблицей `paste`; есть
      `SecretSchemaAnchor` (`#[ORM\Table(name:'secret')]`, явные имена индексов);
      `DoctrineSecretRepository` работает с `secret`; `SecretRecordMapper`/
      колонки не менялись (AC5).
- [ ] `tests/` не обращается к таблице `paste` (все — `secret`);
      `PasteSchemaAnchorTest` переименован; есть
      `RenamePasteToSecretMigrationTest`; `make test` — 0 падений (AC6).
- [ ] Публичные/инфраструктурные имена (`app_paste_*`, `app:paste:purge`,
      `create_paste`/`unlock_paste`, `templates/paste/*`, `#paste-url`, том
      `paste_data`) не изменились (AC7).
- [ ] `make qa` (cs-check, PHPStan L10 — 0 без baseline, deptrac 0, PHPUnit) и
      `make coverage` (Domain/Application ≥ 90%) зелёные; CI-порядок
      `migrate → schema:validate → phpunit` зелёный; новых `@phpstan-ignore` без
      причины/даты нет (AC8).
- [ ] Миграция не логирует/не эмитит plaintext, `SecretKey`, производные ключи,
      `salt/nonce/ciphertext`; не делает `DROP` данных; `.env.local` не добавлен
      (AC9).
- [ ] ADR 0004 оформлен и переведён в `accepted`; `docs/ARCHITECTURE.md` §5 и
      `ROADMAP.md` актуализированы; `docs/UBIQUITOUS_LANGUAGE.md` не изменён
      (AC10).
- [ ] Артефакты — в `docs/task/006-rename-paste-table-to-secret/`.
