# Ревью: 006-rename-paste-table-to-secret

## Вердикт: approved

Задача реализована в границах требований и плана; блокирующих замечаний нет.
Версия проверена исполнением в Docker-окружении (`php:8.4`, SQLite):

| Проверка | Команда | Результат |
|---|---|---|
| Полный тест-набор | `docker compose exec -T php vendor/bin/phpunit --no-coverage` | **OK (154 tests, 994 assertions)** |
| Тесты миграции | `... phpunit tests/Integration/Migrations` | **OK (5 tests, 320 assertions)** |
| PHPStan L10 | `... phpstan analyse` | **No errors** |
| PHP-CS-Fixer | `... php-cs-fixer fix --dry-run` | **0 of 98 files** |
| deptrac | `... deptrac analyse` | **0 violations** |
| AC1/AC3 (чистая БД) | `rm var/data/test.db && migrations:migrate -n --env=test && schema:validate --env=test` | 2 миграции, 8 SQL; mapping OK, schema in sync |
| Покрытие | `phpunit --coverage-clover` + `tools/check-coverage.php` | **100.00%** Domain/Application (≥ 90%) |

Вердикт `approved` не снимает замечаний N1–N3 (ниже) — они не блокируют
передачу на G3.

## Соответствие requirements.md

| AC | Статус | Доказательство |
|---|---|---|
| AC1 — чистая БД даёт `secret` | выполнено (исполнение) | Свежий `migrations:migrate` выполняет обе миграции, `schema:validate` — schema in sync; историческая `Version20260909000000` остаётся первой |
| AC2 — сохранение данных | выполнено (тест) | `RenamePasteToSecretMigrationTest::testMigrationRenamesPasteToSecretPreservingDataIndexesAndAutoincrement` (in-memory + файловая SQLite): 3 строки, дырки `id` 1/5/9, `hint = null/''/<> null`, BLOB с `\x00`, побайтовое равенство через `hex()`, число строк неизменно |
| AC3 — индексы согласованы с ORM | выполнено (тест + CI-путь) | `MigrationSchemaMatchesOrmMetadataTest` строит БД **миграциями**, затем сравнивает с ORM-метаданными (`schemaInSyncWithMetadata`, `getUpdateSchemaSql() === []`) и явно проверяет `uniq_secret_token`/`idx_secret_expires_at`; `schema:validate` зелёный |
| AC4 — обратимость | выполнено (тест) | `up → down → повторный up`; на каждом шаге проверяются таблица, строки, индексы и `sqlite_sequence` |
| AC5 — prod-код использует `secret` | выполнено | `DoctrineSecretRepository` — 4 SQL-литерала `secret` (`:26,:46,:60,:71`); `PasteSchemaAnchor` отсутствует, есть `SecretSchemaAnchor` (`#[ORM\Table(name:'secret')]`, явные индексы, non-final); `doctrine.yaml` mapping `Schema/` без изменений; маппер/колонки не тронуты |
| AC6 — тесты синхронизированы | выполнено | `PasteSchemaAnchorTest` → `SecretSchemaAnchorTest`; все табличные литералы — `secret`; `MigrationSchemaMatchesOrmMetadataTest`; `make test` — 0 падений |
| AC7 — поведение и публичные имена неизменны | выполнено | `git diff` пуст для `config/routes.yaml`, форм, `templates/`, `compose*.yaml`, `deploy/deploy.sh`, `src/Schedule.php`; functional/wiring-тесты (`app:paste:purge`, `app_paste_*`, `create_paste`/`unlock_paste`, `#paste-url`, `paste_data`) зелёные без смены ожиданий |
| AC8 — качество и CI | выполнено | PHPStan L10 / CS-Fixer / deptrac / PHPUnit — зелёные; покрытие 100%; CI-порядок воспроизведён; `down()` покрыт тестом; новых `@phpstan-ignore` нет (счётчик 11, как до задачи) |
| AC9 — безопасность миграции | выполнено | миграция не логирует и не эмитит payload; нет `DROP TABLE` данных; `isTransactional()` (по умолчанию true) + DDL SQLite в транзакции; `.env.local` не добавлен |
| AC10 — документы и трассируемость | выполнено | ADR 0004 (`accepted`), актуализации ADR 0002/0003, `ARCHITECTURE.md` §5, `ROADMAP.md` (006 `[~]`); `UBIQUITOUS_LANGUAGE.md` не изменён |

Решения G1 соблюдены: Q1-A (anchor переименован, DBAL-адаптер сохранён),
Q2-B (явные `uniq_secret_token`/`idx_secret_expires_at`), Q3-A (публичные и
инфраструктурные имена не трогались), Q4-A (интеграционный тест up/down на
данных), Q5-A (короткий ADR 0004, `schema:validate` как гейт), Q6-A
(`Version20260909000000` не редактировалась — `git diff` по файлу пуст).

## Соответствие plan.md

- **Ш1 (атомарное переименование).** Совпадает с планом: новая миграция,
  `SecretSchemaAnchor` → таблица/index-имена, 4 SQL адаптера, переименование и
  правка существующих тестов — одним изменением.
- **Ш2 (тест миграции).** Реализован по дизайну плана: kernel-less
  `DependencyFactory` на изолированной SQLite, без контейнера и правок
  composer autoload; версии адресуются строками (совместимо с PHPStan L10).
  Дополнительно добавлен `MigrationSchemaMatchesOrmMetadataTest`, закрывающий
  направление «миграция → ORM» (в плане он прямо не назван, но логично
  дополняет AC1/AC3; объём не расширен — тест относится к той же миграции).
- **Ш3 (документы).** ADR 0004 `accepted`, ADR 0002/0003 актуализированы,
  `ARCHITECTURE.md` §5 и `ROADMAP.md` обновлены.
- **Ш4 (финальная верификация).** Подтверждено прогоном (таблица выше).

Отклонений от объёма нет. Публичные имена и `config/*`, `compose*`,
`deploy.sh`, CI оставлены как в плане.

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- §3.3 (направление зависимостей), §5 (границы с фреймворком), §6 (ошибки):
  изменений в `Domain`/`Application` нет; меняется только
  `Secret/Infrastructure` + `migrations/`. deptrac — 0 нарушений.
- §5.5 (Doctrine): доменные классы без ORM-атрибутов; anchor — технический
  класс, не сервис (`config/services.yaml` исключает каталог `Schema/`);
  репозиторий — DBAL-адаптер, QueryBuilder не выходит наружу.
- CONVENTIONS §1 (strict_types, `final` по умолчанию, типы), §4 (тонкие
  контроллеры — не затронуты), §5 (маппинг в Infrastructure, миграция вместе с
  моделью), §7 (расположение/название тестов) — соблюдены.
- ADR 0004 `accepted`, ADR 0003/0002 получили актуализирующие пометки без
  переписывания истории — соответствует ARCHITECTURE §8.
- Политика явных имён индексов закреплена и в ORM (`SecretSchemaAnchor:13-14`),
  и в миграции (`Version20260910000000:22-23`) — авто-хеш `UNIQ_<hash(...)>`
  не используется.

## Найденные проблемы (блокирующие / неблокирующие)

**Блокирующих замечаний нет.**

### Неблокирующие

- **N1. Устаревший статус в `plan.md`.**
  `docs/task/006-rename-paste-table-to-secret/plan.md:3` — «ожидает G2», и
  `:7` — «ADR 0004 приложен (`proposed`)», тогда как
  `docs/adr/0004-rename-paste-table-to-secret.md:3` — `accepted`, задача
  фактически на G3. Рекомендация: синхронизировать шапку `plan.md` (G2 пройден)
  при подготовке к коммиту, чтобы артефакты не противоречили ADR. Точной ссылки
  на нарушение стандарта нет — это сопроводительная неточность.
- **N2. Ops-задача по откату БД не заведена в roadmap.**
  `docs/adr/0004-rename-paste-table-to-secret.md:120-123` и план фиксируют риск
  «старый образ ищет `paste`» и называют автоматический откат отдельной
  ops-задачей, но в `docs/task/ROADMAP.md` отдельной записи нет. Требованием
  AC10 ops-задача не предусмотрена, поэтому замечание неблокирующее; для
  трассируемости стоит добавить пункт в roadmap/journal.
- **N3. `migrations/` вне PHPStan `paths`.** `phpstan.neon:8` —
  `paths: [src, tests]`, поэтому `Version20260910000000.php` статически не
  анализируется (как и историческая миграция). Это соответствует текущему
  стандарту QUALITY §2.1 и не является дефектом задачи; предложение —
  рассмотреть включение `migrations` отдельной задачей качества.

### Оценка известных рисков

- **Откат релиза** (старый код ↔ `secret`) — задокументирован в ADR 0004 и
  плане; автоматического `down()`/restore в `deploy.sh` нет. Это осознанное
  ограничение, вынесенное за объём 006 (см. N2).
- **Смешанный bootstrap-сценарий** (БД, созданная `SchemaTool` + пустая история
  миграций) — зафиксирован в плане как неподдерживаемый путь (prod — только
  миграции, CI — fresh DB). Не воспроизводится ни в одном из поддерживаемых
  путей.
- **`sqlite_sequence`/`AUTOINCREMENT`** — SQLite обновляет имя последовательности
  при rename; проверено тестом (`fetchSequenceNames`, `id` новой вставки > 9).
- **N6 из 005** (nullable-сигнатура anchor) — остаётся вне объёма, как и
  согласовано в requirements; на `schema:validate` не влияет (проверено).

## Протечки абстракций между слоями

Протечек нет. Изменения локализованы в `Secret/Infrastructure/Persistence/
Doctrine` и `migrations/`. `Domain`/`Application` не тронуты; SQL не появляется
в домене и не покидает адаптер. `Presentation` продолжает работать через
Application/Domain, контроллеры не изменялись. deptrac — 0 нарушений,
`Secret_Presentation` не импортирует `Infrastructure`.

## Тестовое покрытие

- Новый `tests/Integration/Migrations/RenamePasteToSecretMigrationTest.php`
  (5 тестов, 320 ассертов) реально исполняет обе миграции на изолированной
  SQLite (`:memory:` и файловая БД) и проверяет AC2/AC4: строки и BLOB
  побайтово, `hint`, дырки `id`, индексы/колонки, `sqlite_sequence`, повторный
  `up`, guard `sqlite_version >= 3.25.0`.
- `MigrationSchemaMatchesOrmMetadataTest` **не тавтологичен**: он строит схему
  *только миграциями* (CI/prod-путь), а метаданные получает независимо из
  `ORMSetup` по `SecretSchemaAnchor`, после чего сверяет
  `schemaInSyncWithMetadata()`/`getUpdateSchemaSql()` и явные имена индексов.
  Зеркальный `SecretSchemaAnchorTest` (SchemaTool → метаданные) намеренно
  дополняет его, а не заменяет.
- `SecretSchemaAnchorTest` проверяет отсутствие второго авто-хешированного
  unique-индекса на `token` (`assertCount(1, $uniqueTokenIndexes)`).
- Полный набор — 154 теста / 994 ассерта, 0 падений; покрытие
  Domain/Application — 100%; новых `@phpstan-ignore` нет.

## Замечания по безопасности

- Миграция не содержит `DROP TABLE`, не пересоздаёт данные, не логирует
  `plaintext`/`SecretKey`/производные ключи/`salt`/`nonce`/`ciphertext`.
- `up()`/`down()` симметричны, DDL транзакционен на SQLite; при ошибке
  откатывается весь шаг.
- Публичные и защитные контракты не ослаблены: маршруты, wire-формы, CSRF,
  rate limiting, `Cache-Control: no-store`, TTL и крипто-формат не менялись
  (AC7 подтверждён functional/wiring-тестами).
- `.env.local` не добавлен; `config/reference.php` в diff отсутствует (не
  тронут).

## Готовность к G3

Блокирующих замечаний нет; неблокирующие N1–N3 носят документарный/
сопроводительный характер. Изменение можно передавать на G3: diff локален,
гейты `make qa`/`make coverage`/`schema:validate` воспроизведены ревьюером.
