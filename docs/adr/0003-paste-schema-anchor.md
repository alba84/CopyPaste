# 0003. ORM schema-anchor таблицы `paste` после удаления легаси

- Статус: accepted
- Дата: 2026-09-12
- Автор: architect
- Связанная задача: docs/task/005-remove-legacy-paste/
- Дополняет: ADR 0002

## Контекст

Задача 005a удаляет последний легаси-остров `App\*` после переноса сценариев
`CreateSecret` (002), `RevealSecret` (003) и `PurgeExpiredSecrets` (004) в модуль
`Secret`. Частью удаления являются `App\Entity\Paste` и
`App\Repository\PasteRepository`.

`App\Entity\Paste` — единственный ORM-класс проекта. Его метаданные используются
не рантаймом нового кода, а инструментами схемы:

1. `config/packages/doctrine.yaml` регистрирует единственный mapping `App` →
   `%kernel.project_dir%/src/Entity` (`type: attribute`).
2. `doctrine:schema:validate --env=test` (CI) сравнивает интроспектированную БД с
   ORM-мета-схемой. Без ORM-метаданных платформа предлагает `DROP TABLE paste`,
   проверка возвращает `false`, команда завершается кодом `2`.
3. Functional/integration-тесты (`PasteFlowTest`, `CreateSecretFlowTest`,
   `RevealSecretFlowTest`, `DoctrineSecretRepositoryTest`,
   `DoctrineSecretRepositoryPurgeTest`, `PurgeExpiredSecretsCommandTest`)
   создают схему через `SchemaTool::dropSchema()/createSchema()` по
   `getAllMetadata()`. Без метаданных это no-op: локально `make test` перестаёт
   создавать `paste`.
4. Если оставить mapping на удалённый каталог `src/Entity`, сборка контейнера
   падает (`InvalidArgumentException: Specified non-existing directory ...`).

При этом ADR 0002 запретил второй ORM-маппинг на `paste`: `SecretRecord` —
DBAL-модель без ORM-атрибутов, `DoctrineSecretRepository` работает через
`Doctrine\DBAL\Connection`. Два ORM-маппинга одной таблицы привели бы к
конфликту `CREATE TABLE`/индексов и риску дрейфа.

G1 задачи 005 (Q1-A) постановил: удалить легаси в 005a, сохранить работу
`SchemaTool`/`schema:validate` без миграций и без смены quality-гейта, перенеся
роль ORM-владельца схемы в минимальный якорь внутри модуля `Secret`. Q1-A
обязал оформить это решение ADR (Q6).

## Рассмотренные варианты

1. **A. Минимальный ORM schema-anchor в `Secret/Infrastructure`.** Удалить
   `App\Entity\Paste`/`PasteRepository` и прочий легаси; завести
   `PasteSchemaAnchor` — attribute-класс, отображающий `paste` ровно так, как это
   делала легаси-сущность. Класс используется только инструментами схемы;
   рантайм-персистентность остаётся за DBAL-адаптером.
   *Плюсы:* нет миграций и смены гейта; `SchemaTool`/`schema:validate` зелёные;
   достигается цель 005a и целевой deptrac; тест-инфраструктура не переписывается.
   *Минусы:* в инфраструктуре живут два представления одной таблицы (ORM-якорь +
   DBAL-адаптер) → небольшой риск дрейфа, требующий явных проверок.

2. **B. Отложить удаление `Entity\Paste`/`PasteRepository` в 005b.**
   *Плюсы:* ноль нового кода. *Минусы:* «удалить легаси» в 005a не достигнуто,
   целевой deptrac недостижим, объём противоречит ROADMAP.

3. **C. Migrations-only: удалить ORM совсем.** Тесты создают схему выполнением
   миграций, quality-гейт `schema:validate` меняется.
   *Плюсы:* соответствует долгосрочной цели (миграции — источник схемы).
   *Минусы:* смена стандарта QUALITY §4 и тестовой инфраструктуры → обязателен
   отдельный ADR и одобрение; теряется колоночная валидация схемы. Дороже для
   шага «без миграции».

## Решение

Выбран **вариант A**. Вводится `final` attribute-класс `PasteSchemaAnchor` в
`src/Secret/Infrastructure/Persistence/Doctrine/Schema/` со свойствами
`id/token/hint/salt/nonce/ciphertext/createdAt/expiresAt` и
`#[ORM\Table(name: 'paste')]` + `#[ORM\Index(name: 'idx_paste_expires_at',
columns: ['expires_at'])]` — метаданные структурно идентичны удалённой
`App\Entity\Paste`.

Изменение `config/packages/doctrine.yaml`: mapping `App` → `src/Entity` заменяется
на mapping `SecretSchema` → `%kernel.project_dir%/src/Secret/Infrastructure/Persistence/Doctrine/Schema`
(`prefix: App\Secret\Infrastructure\Persistence\Doctrine\Schema`).

`PasteSchemaAnchor` не является сервисом: каталог `Schema/` исключается из
`App\:`-авторегистрации. Порт `SecretRepository`/адаптер
`DoctrineSecretRepository` не меняются, новых ORM-классов на `paste` нет.
Агрегат `Secret` передаётся рантаймом без ORM.

Почему не B/C:

- B противоречит цели 005a и оставляет легаси-слои в deptrac.
- C меняет качественный стандарт (QUALITY §4) и тестовый bootstrap; это
  отдельное решение, логичное для 005b (переименование `paste → secret`), а не
  для механического удаления легаси. Q1-A выбрал A именно чтобы не совмещать два
  перехода владения схемой в одной задаче.

Границы: якорь — технический артефакт без доменного значения, в
`docs/UBIQUITOUS_LANGUAGE.md` не попадает. Он будет переименован/удалён в 005b
при переходе на `secret`/migrations-only по отдельному решению.

## Последствия

- Проще: удаление легаси не требует миграций и не трогает quality-гейт;
  `doctrine:schema:validate` и `SchemaTool` продолжают работать; достижим
  целевой deptrac без бизнес-слоёв `App\*`.
- Сложнее: одна таблица `paste` описана дважды — ORM-метаданными якоря и SQL
  внутри `DoctrineSecretRepository`. Дрейф контролируется:
  1. `doctrine:schema:validate --env=test` в CI (сравнение БД с якорем);
  2. новый интеграционный тест `PasteSchemaAnchorTest`
     (`SchemaValidator::schemaInSyncWithMetadata()` + сверка колонок
     `PRAGMA table_info(paste)` с `ClassMetadata::getColumnNames()`);
  3. существующие интеграционные тесты `DoctrineSecretRepository*`,
     исполняющие запись/чтение через DBAL на схеме, созданной из якоря.
- При добавлении/изменении колонок `paste` (до 005b) правка обязана идти
  одной миграцией и одновременно якорем + адаптером; `schema:validate` это
  поймает.
- 005b (`006`) переименует таблицу и перенесёт владение схемой; при выборе
  migrations-only якорь удаляется. До этого `App\Schedule`,
  `App\EventSubscriber\SQLitePragmaSubscriber`, `App\Kernel` остаются
  техническими слоями (Q2-A).

## Совместимость со стандартами

После перевода ADR в `accepted` (гейт G2) обновляются:

- `docs/ARCHITECTURE.md` §5 — уточняющая строка: для `Secret` допускается
  минимальный ORM schema-anchor в `Infrastructure`, используемый только
  инструментами схемы, при DBAL-адаптере как единственной рантайм-персистентности.
- `docs/QUALITY.md` §2.2 — в ruleset добавляются минимальные технические слои
  `Kernel`/`Schedule`/`EventSubscriber` (Q2-A; ADR не требуется — это исполнение
  уже принятого решения).
- `docs/task/ROADMAP.md` — статус 005/005b.

До одобрения правила применяются как согласованные на G1 (Q1-A, Q2-A).
