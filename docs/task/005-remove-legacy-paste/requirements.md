# 005-remove-legacy-paste: удаление легаси-слоя `App\*` после переноса сценариев в модуль `Secret`

> Задача `005` разбита на `005a` (этот документ, **без миграции БД**) и `005b`
> (`006-rename-paste-table-to-secret`, переименование таблицы `paste → secret`).
> Источник разбиения — `docs/task/ROADMAP.md`, журнал: «Задача 005 разделена на
> 005a и 005b».

## Контекст и проблема

Задачи 002–004 вынесли все продуктовые сценарии в модуль `Secret`:

- `CreateSecret`, `RevealSecret`/`LoadRevealSecret`, `PurgeExpiredSecrets`
  работают через порты `SecretRepository`, `Crypto\Domain\Cryptography`,
  `TokenGenerator`; адаптеры — `DoctrineSecretRepository` (DBAL) и
  `SodiumCryptography`; персистентность — таблица `paste` (ADR 0002).
- В `App\*` остались острова легаси: `Controller\PasteController` (только
  `created`), `Entity\Paste`, `Repository\PasteRepository`, `Dto\{CreatePasteData,
  UnlockPasteData,EncryptedPayload}`, `Form\{CreatePasteType,UnlockPasteType}`,
  `Service\EncryptionService`, `config/services_test.yaml`, а также слои
  `deptrac.yaml` для `Controller/Form/Dto/Entity/Repository/Service/Command`.
- После 003 `PasteController::view` удалён; после 004 удалён
  `PurgeExpiredPastesCommand` и мёртвый `PasteRepository::purgeExpired()`.
  Каталог `src/Command/` пуст.

Задача 005a — довести начатое: перенести `PasteController::created`
(`/created/{token}`) в `Secret/Presentation/Http` и удалить легаси-код, **не
меняя схему БД и наблюдаемое поведение** (маршруты/URL, wire-контракты форм,
тексты, крипто-формат, TTL, rate limiting, `no-store`).

### Ключевой риск: `App\Entity\Paste` — единственный ORM-маппинг

Факты (проверено по коду/конфигам/vendor):

1. `config/packages/doctrine.yaml` регистрирует единственный mapping `App` →
   `%kernel.project_dir%/src/Entity` (`type: attribute`). Единственный класс
   там — `App\Entity\Paste`, отображённый на таблицу `paste`
   (`ORM\Table(name: 'paste')`, индекс `idx_paste_expires_at`). Новый
   `SecretRecord` — DBAL-модель без ORM-атрибутов (ADR 0002).
2. **Тестовая схема создаётся `SchemaTool` по ORM-метаданным.** В
   `PasteFlowTest`, `CreateSecretFlowTest`, `RevealSecretFlowTest`,
   `LegacyInteropTest`, `DoctrineSecretRepositoryTest`,
   `DoctrineSecretRepositoryPurgeTest`, `PurgeExpiredSecretsCommandTest` в
   `setUp()`/`recreateSchema()` выполняется
   `getMetadataFactory()->getAllMetadata()` + `SchemaTool::dropSchema()` +
   `SchemaTool::createSchema()`. Миграции в тестах не используются.
3. **CI** (`.github/workflows/ci.yml`) выполняет:
   `doctrine:migrations:migrate -n --env=test` → `doctrine:schema:validate
   --env=test` → `phpunit`. Локальная цель `make test` миграции **не** запускает
   (`.env.test`: `sqlite:///var/data/test.db`).
4. При пустых ORM-метаданных `SchemaTool::getUpdateSchemaSql()` сравнивает
   интроспектированную БД (таблица `paste` есть) с пустой мета-схемой;
   `AbstractPlatform::getAlterSchemaSQL()` добавляет `DROP TABLE paste`, поэтому
   `SchemaValidator::schemaInSyncWithMetadata()` = `false`, а
   `doctrine:schema:validate` завершается кодом **2**
   (`ValidateSchemaCommand`: `$exit += 2`). То есть удаление `Entity\Paste` без
   компенсации делает CI красным.
5. `SchemaTool::createSchema([])`/`dropSchema([])` — no-op: локально `make test`
   перестанет создавать `paste` (в CI таблица останется после миграции —
   расхождение сред, недетерминизм).
6. Если удалить каталог `src/Entity`, оставив mapping `App` в
   `doctrine.yaml`, сборка контейнера упадёт:
   `AbstractDoctrineExtension::load` бросает `InvalidArgumentException`
   (`Specified non-existing directory ... as Doctrine mapping source`).
7. `config/routes.yaml` импортирует `../src/Controller/` (`namespace:
   App\Controller`, `type: attribute`). После удаления `PasteController`
   каталог/блок надо убрать, иначе Symfony не загрузит несуществующий
   ресурс.
8. `config/services.yaml` содержит `exclude: ../src/Entity/` и прочие ветки,
   которые надо синхронизировать с удаляемыми каталогами. `src/Command/` уже
   пуст.
9. `config/services_test.yaml` делает `App\Service\EncryptionService` публичным
   в test-env. Это единственная причина его существования (production-ссылок
   на сервис после 003 нет); файл заявлен к удалению в 005.
10. `deptrac.yaml` содержит бизнес-слои `Controller/Form/Dto/Entity/Repository/
    Service/Command`; целевой набор QUALITY §2.2 (закреплён ADR 0002) их не
    содержит, но не содержит и слоёв для `App\Kernel`, `App\Schedule`,
    `App\EventSubscriber\SQLitePragmaSubscriber`, которые остаются.

### Фактическая карта ссылок на легаси

Production (`src/`):

- `PasteController` → `Entity\Paste`, `Repository\PasteRepository`,
  `paste/created.html.twig`, `app_paste_created`, `app_paste_view`.
- `Entity\Paste` → `Dto\EncryptedPayload`, `Repository\PasteRepository`
  (`repositoryClass`).
- `Repository\PasteRepository` → `Entity\Paste`.
- `Service\EncryptionService` → `Dto\EncryptedPayload`.
- `Form\{CreatePasteType,UnlockPasteType}` и `Dto\{CreatePasteData,
  UnlockPasteData}` — мёртвые (ни один контроллер их не использует с 002/003).
- Новый `Secret`-код использует только own-типы; легаси-термины в него не
  проникают, кроме строк: route name `app_paste_created`, пути шаблонов
  `paste/*.twig`.

Tests (`tests/`):

- `Functional/PasteFlowTest` (Dto\EncryptedPayload, Entity\Paste),
  `Functional/CreateSecretFlowTest` (Entity\Paste, PasteRepository,
  EncryptionService), `Functional/RevealSecretFlowTest` (те же +
  `persistLegacyPaste`), `Functional/PurgeExpiredSecretsCommandTest`
  (PasteRepository), `Integration/Secret/LegacyInteropTest` (все три),
  `Integration/Secret/Infrastructure/Persistence/Doctrine/
  DoctrineSecretRepositoryTest` (Entity, PasteRepository),
  `Integration/Crypto/Infrastructure/Sodium/SodiumCryptographyTest`
  (Dto\EncryptedPayload, EncryptionService),
  `Unit/Service/EncryptionServiceTest`.

## Пользовательская цель

После 005a в кодовой базе не остаётся легаси-бизнес-кода `App\*`:
сценарий «Ссылка готова» (`/created/{token}`) обслуживается модулем `Secret`,
новый код использует терминологию `Secret`, границы слоёв соответствуют
принятой архитектуре (ADR 0002), схема БД и всё наблюдаемое поведение
неизменны, миграций нет. Схема и миграции переходят в 005b.

## Объём (in scope)

1. **Перенос `created`.** Новый тонкий контроллер в
   `src/Secret/Presentation/Http` (имя — решение плана, напр.
   `SecretCreatedController`) на `SecretRepository::findByToken(Token)`:
   маршрут `app_paste_created`, путь `/created/{token}`,
   `requirements: ['token' => '[a-f0-9]{32}']`, `methods: ['GET']`; рендер
   страницы «Ссылка готова» с абсолютной ссылкой на `app_paste_view` и сроком
   `expiresAt` в формате `d.m.Y, H:i`. Удаление `PasteController` и блока
   `controllers` в `config/routes.yaml`, если каталог `src/Controller`
   исчезает.
2. **Удаление production-легаси:** `App\Controller\PasteController`,
   `App\Dto\{CreatePasteData,UnlockPasteData,EncryptedPayload}`,
   `App\Form\{CreatePasteType,UnlockPasteType}`,
   `App\Service\EncryptionService`, `config/services_test.yaml`.
3. **Судьба `App\Entity\Paste`/`App\Repository\PasteRepository`** — по решению
   Q1 (варианты A/B/C). По умолчанию (рекомендация A) удаляются, а роль
   ORM-владельца схемы `paste` переносится в минимальный
   persistence/schema-якорь внутри `Secret/Infrastructure` (без изменения DDL).
4. **Конфиги:** синхронизировать `config/services.yaml` (excludes),
   `config/routes.yaml`, `config/packages/doctrine.yaml` (mapping) с удалёнными
   каталогами; `deptrac.yaml` привести к целевому набору без бизнес-слоёв
   `App\*` (см. Q2).
5. **Тесты:** переписать/удалить завязанные на легаси (см. AC9):
   - `PasteFlowTest` — на `SecretRepository`/новый код без смены ожиданий;
   - `CreateSecretFlowTest`/`RevealSecretFlowTest` — seeding/проверки через
     `SecretRepository` и `Cryptography` вместо `Paste`/`EncryptionService`;
   - `LegacyInteropTest` — удалить (после удаления легаси интероп не с чем
     проверять; покрытие чтения/записи остаётся в `DoctrineSecretRepository*`);
   - `DoctrineSecretRepositoryTest::testSaveIsReadableByLegacyRepository` —
     удалить/переписать на новый путь;
   - `SodiumCryptographyTest` — убрать легаси-интероп-кейсы и `EncryptionService`;
   - `Unit/Service/EncryptionServiceTest` — удалить;
   - `PurgeExpiredSecretsCommandTest::testLegacyPurgeCommandAndRepositoryMethodAreGone`
     — обновить ассерты (проверка отсутствия классов/методов).
   Ожидания поведения (статусы, тексты, URL) не меняются.
6. **Документы:** этот `requirements.md`; по итогам — `plan.md`,
   `ROADMAP.md` (`005` → `[x]`/`[~]`), при необходимости ADR (Q1/Q2).

## Вне объёма (out of scope)

- **Изменение схемы БД и миграции.** Никаких новых/правленых файлов в
  `migrations/`, никакого `ALTER TABLE`, переименования `paste → secret`,
  изменения колонок/индексов. Это `005b` (`006`).
- **Удаление/переименование таблицы `paste`** и переход на migrations-only как
  стандарт (если Q1 не выберет вариант C — тогда только через ADR и отдельное
  решение; см. Q1).
- **Переименование внутренних легаси-имён, если они наблюдаемы:** route name
  `app_paste_created`, `app_paste_view`, `create_paste`/`unlock_paste` (wire),
  имя команды `app:paste:purge`, DTO-путь `app_paste_*`. Сохраняются как есть;
  переименование — отдельная задача.
- **Логика известной гонки** `UniqueTokenGenerator` (TOCTOU) и обработка
  `UniqueConstraintViolationException` — отдельная задача.
- **Доменные события, event bus, второй потребитель** (§4.3).
- **Контекст `Access`** (rate limiting/квоты) — задача `007`.
- **Новые контексты/модули**, CQRS, смена СУБД, микросервисы.
- **Изменение крипто-формата, TTL, текстов ошибок, `Cache-Control`,
  CSRF/rate limiting.**

## Доменные термины (новые/уточнённые)

**Новых доменных терминов нет.** Используются уже зафиксированные `Secret`,
`Token`, `Reveal`, `Expiration`, `SecretRepository`, `Encryption`/`Cryptography`.
Термин `Paste` остаётся только как имя физической таблицы и наблюдаемых
легаси-идентификаторов (route/command/wire), новым кодом не применяется.

**`docs/UBIQUITOUS_LANGUAGE.md` не изменяется** — ни новых терминов, ни
изменения значений. Если на G1 будет принят Q1-A (schema-only ORM-якорь),
«якорь» остаётся техническим именем без доменного значения и в глоссарий не
идёт. Если Q1-A добавит тип `SecretCreatedView`/`CreatedSecretView` — это
техническая композиция, тоже без глоссария.

## Критерии приёмки (Given–When–Then)

> AC4/AC7/AC8 зависят от решения Q1. Формулировки даны для **рекомендуемого
> варианта A**; при выборе B/C они правятся на G1.

**AC1 — `/created/{token}` обслуживается модулем `Secret`.**
Given существует `Secret` с валидным токеном `T`;
When `GET /created/{T}`;
Then ответ `200`, `h1` = «Ссылка готова», `input#paste-url` = абсолютный URL
`app_paste_view` для `T` (`generateUrl(..., ABSOLUTE_URL)`), уведомление
содержит `expiresAt` в формате `d.m.Y, H:i`; маршрут сохранён как
`app_paste_created`, `/created/{token}`, `requirements ['token' =>
'[a-f0-9]{32}']`, `methods ['GET']`.

**AC2 — неизвестный токен даёт нейтральный 404.**
Given нет `Secret` с валидным по формату токеном `T`;
When `GET /created/{T}`;
Then `404` (детали не раскрываются).

**AC3 — истёкший Secret на `/created/{token}` по-прежнему открывается.**
Given `Secret` с `T` и `expiresAt` в прошлом;
When `GET /created/{T}`;
Then `200` с тем же содержимым, что в AC1 (легаси `findOneBy` не фильтровал по
сроку; поведение сохраняется).

**AC4 — production-код свободен от удаляемого легаси.**
Given выполнен 005a (вариант Q1-A/C);
When ищутся ссылки в `src/`;
Then отсутствуют классы/ссылки `App\Controller\PasteController`,
`App\Dto\{CreatePasteData,UnlockPasteData,EncryptedPayload}`,
`App\Form\{CreatePasteType,UnlockPasteType}`,
`App\Service\EncryptionService`, `App\Entity\Paste`,
`App\Repository\PasteRepository`; `config/services_test.yaml` отсутствует.
При Q1-B `App\Entity\Paste`/`PasteRepository` допускаются только как
schema/test-anchor (production не использует, зафиксировано в plan/notes).

**AC5 — наблюдаемое поведение форм и Reveal не изменилось.**
Given существующие functional-проверки `/`, `/p/{token}`;
When они выполняются;
Then wire-контракты `create_paste[...]`/`create_paste[_token]`,
`unlock_paste[...]`/`unlock_paste[_token]`, `csrf_token_id`, статусы, тексты,
`Cache-Control: no-store`, лимиты 10/мин, TTL — без изменений; ожидания
существующих тестов не переписываются.

**AC6 — тонкий `created`-контроллер.**
Given новый контроллер `created`;
When выполняется запрос;
Then контроллер только читает `Secret` через `SecretRepository::findByToken`,
формирует view-модель и рендерит; в нём нет QueryBuilder/SQL, расчёта TTL,
Sodium, `persist/flush`; в шаблон не передаются `salt/nonce/ciphertext/hint/
plaintext` (только срок и URL).

**AC7 — `deptrac.yaml` приведён к целевому набору.**
Given целевой набор QUALITY §2.2/ADR 0002;
When `make deptrac`;
Then `Violations 0, Errors 0`; бизнес-слои `Controller/Form/Dto/Service/Command`
отсутствуют; при Q1-A/C отсутствуют `Entity/Repository`; `App\Kernel`,
`App\Schedule`, `App\EventSubscriber\SQLitePragmaSubscriber` разрешены (Q2).

**AC8 — схема БД и миграции не тронуты; `schema:validate` зелёный.**
Given каталог `migrations/` и DDL таблицы `paste`;
When `git diff` по `migrations/` и `doctrine:schema:validate --env=test`;
Then миграции не изменены/не добавлены; DDL `paste` (колонки/индексы) не
менялся; `doctrine:schema:validate --env=test` — `mapping OK`, `schema in
sync`, exit 0; тестовая схема продолжает создаваться (Q1-A/B: `SchemaTool` по
ORM-метаданным; Q1-C: миграциями, тогда гейт/AC меняются по ADR).

**AC9 — тесты переведены на новый код.**
Given `tests/`;
When ищутся ссылки и запускается `make test`;
Then нет ссылок на удалённые классы (кроме негативных проверок
`class_exists`/`method_exists`); `LegacyInteropTest` и
`EncryptionServiceTest` удалены; легаси-интероп-кейсы `SodiumCryptographyTest`
удалены; seeding/проверки hint/счётчиков/expiry идут через `SecretRepository`
(и `Cryptography`); все тесты зелёные, число падений — 0.

**AC10 — качество.**
When `make qa` (cs-check, stan level 10, deptrac, tests) и `make coverage`;
Then 0 ошибок; покрытие `src/*/{Domain,Application}` ≥ 90%; новых
`@phpstan-ignore` без причины/даты нет; baseline отсутствует.

**AC11 — безопасность.**
Given логи и HTTP-ответы;
When анализируются;
Then `plaintext`, `SecretKey`, производные ключи, `salt/nonce/ciphertext` не
логируются и не попадают в `created`-страницу сверх легаси; CSRF, rate
limiting, `no-store`, проверка срока не ослаблены; `.env.local` не добавлен.

**AC12 — документы и трассируемость.**
When задача закрывается;
Then `ROADMAP.md` обновлён (`005` статус), `requirements.md`/`plan.md`/`review.md`
на месте; ADR оформлен только если это требует решение Q1 (Q1-C — обязателен,
Q1-A — рекомендуется ADR 0003) или если фактически меняется стандарт; удаление
deptrac-слоёв ADR не требует; глоссарий не изменён.

## Открытые вопросы (и ответы человека)

> **Статус: G1 пройден (все рекомендации A).** Решение Q1-A: минимальный
> ORM schema-anchor в `Secret/Infrastructure` + ADR 0003. Q2–Q6 — по
> рекомендациям аналитика.

### Q1 (блокирующий) — владелец ORM-схемы после удаления `App\Entity\Paste`

Удаление единственного ORM-маппинга ломает `SchemaTool` в тестах и
`doctrine:schema:validate` (факты 2–6 раздела «Ключевой риск»). Варианты:

- **A (рекомендую). Минимальный schema-anchor в `Secret/Infrastructure`.**
  Удалить `App\Entity\Paste`/`PasteRepository` и все прочие легаси-классы;
  перенести роль ORM-владельца таблицы `paste` в минимальный
  persistence/schema-якорь внутри
  `src/Secret/Infrastructure/Persistence/Doctrine` (attribute-класс только с
  ORM-полями либо XML-маппинг, разрешённый ARCHITECTURE §5), используемый
  **только** инструментами схемы. `DoctrineSecretRepository` остаётся DBAL.
  **Плюсы:** достигается цель 005a (нет `App\*`-легаси, целевой deptrac), нет
  миграции, нет смены quality-гейта, тест-инфраструктура не переписывается.
  **Минусы:** в инфраструктуре живут два представления одной таблицы (ORM-якорь
  + DBAL-адаптер) — небольшой риск дрейфа, закрываемый `schema:validate` и
  интеграционными тестами; требуется зафиксировать решение (рекомендую короткий
  ADR 0003, дополняющий ADR 0002, либо явная запись в plan/notes на G2).
- **B. Отложить удаление `Entity\Paste`/`PasteRepository` в 005b.**
  В 005a удалить только `PasteController`/`Dto`/`Form`/`EncryptionService`/
  `services_test.yaml`; `Entity\Paste`+`PasteRepository` оставить как
  schema/test-anchor, production их не использует.
  **Плюсы:** ноль нового кода, ноль изменений гейта/схемы, минимальный риск.
  **Минусы:** 005a не выполняет заявленную цель «удалить легаси»; deptrac-цель
  недостижима (слои `Entity`/`Repository` остаются), полное удаление и целевой
  deptrac уезжают в 005b; формально «не удалять в 005a» противоречит ROADMAP.
- **C. Migrations-only: удалить ORM совсем.** Удалить `Entity\Paste`/
  `PasteRepository`; тесты создают схему выполнением миграций (не `SchemaTool`);
  quality-гейт `doctrine:schema:validate --env=test` меняется (например,
  `--skip-sync`/проверка отсутствия drift миграций).
  **Плюсы:** соответствует целевой архитектуре (миграции — источник схемы),
  устраняет ORM-дрейф, удобно для 005b.
  **Минусы:** смена стандарта QUALITY §4 и тестовой инфраструктуры → **обязателен
  ADR и одобрение человека**; теряется колоночная валидация `paste` (переносится
  на ревью миграций); правки в `make`/CI/тестах.

**Рекомендация:** **A** для 005a; **C** — предпочтительный долгосрочный
направление, но вынести его в отдельное решение/ADR (логично — в 005b вместе с
переименованием, чтобы не делать два перехода владения схемой). Если человек
выбирает C уже в 005a — 005a обязан включать ADR и переработку тестовой схемы;
если B — пересмотреть объём 005a и deptrac.

**Вопрос к человеку:** какой вариант (A/B/C)? Если A — допустим ли
schema-anchor и нужен ли ADR 0003?

### Q2 — слои deptrac для `Kernel`/`Schedule`/`EventSubscriber`

Целевой набор QUALITY §2.2/ADR 0002 содержит только `Secret_*`, `Crypto_*`,
`Shared`; после удаления бизнес-легаси остаются `App\Kernel`, `App\Schedule`,
`App\EventSubscriber\SQLitePragmaSubscriber`. Варианты:

- **A (рекомендую).** Оставить минимальные технические слои `Kernel`,
  `Schedule`, `EventSubscriber` (инфраструктура bootstrap, не бизнес-легаси) с
  текущими правилами; новые зависимости не вводятся. ADR не требуется — это
  приведение к уже принятой архитектуре (QUALITY §2.2, ADR 0002).
- **B.** Свести их в один слой `Framework`/`Shared_Infrastructure` с правилами
  `Doctrine`/`Symfony`/`Cron`; чище, но шире диффа.
- **C.** Признать uncovered и отключить отчёт — скрывает связи, не рекомендую.

**Рекомендация:** A (или B по вкусу архитектора). **ADR для deptrac не нужен**:
целевой набор уже зафиксирован ADR 0002/QUALITY §2.2; удаление легаси-слоёв —
исполнение уже принятого решения.

### Q3 — имя нового `created`-контроллера и view-модель

- **A (рекомендую).** `SecretCreatedController` + маленькая view-модель
  (напр. `SecretCreatedView` с `expiresAt`), чтобы не отдавать агрегат
  (и его payload) в Twig.
- **B.** Рендерить `Secret` напрямую (`expiresAt`), шаблон назвать
  `secret/created.html.twig`.
- Открыто: остаётся ли путь `templates/paste/created.html.twig` (минимум диффа)
  или каталог `templates/paste/*` переименовывается в `templates/secret/*`
  (внутренне, наблюдаемого поведения не меняет). Рекомендую ограничиться
  `created`, остальные шаблоны — в 008; либо перенести все 4 шаблона сразу, если
  хочется убрать `paste` из презентации.

### Q4 — судьба тестов, завязанных на легаси

- **A (рекомендую).** Как в AC9: `PasteFlowTest` переписать без смены ожиданий;
  переименование класса в `SecretFlowTest` — опционально (легаси-термин из имени
  уходит, но дифф растёт). `LegacyInteropTest`/`EncryptionServiceTest` удалить;
  легаси-интероп в `SodiumCryptographyTest` убрать; seeding — через
  `SecretRepository`.
- **B.** Сохранить `LegacyInteropTest` как «исторический эталон байтов» без
  легаси-кода (фиксированные байты payload) — возможно, но ценности мало.
- Открыто: переименовывать ли `PasteFlowTest` в `SecretFlowTest` в 005a или
  оставить имя до 008; рекомендация — переписать сейчас, переименование решить
  на G1 (по умолчанию — не переименовывать, чтобы минимизировать дифф).

### Q5 — `Entity\Paste`/`PasteRepository`: удалять в 005a или 005b

Следствие Q1. Если Q1-A/C — удаляются в 005a. Если Q1-B — остаются до 005b, и
это надо явно записать в plan/notes как согласованное отступление от ROADMAP.

### Q6 — нужен ли ADR

- **deptrac** — не нужен (следует ADR 0002/QUALITY §2.2).
- **Q1-A** (schema-anchor) — рекомендую короткий **ADR 0003** «владение схемой
  `paste` после удаления легаси», дополняющий ADR 0002; на усмотрение
  архитектора/человека.
- **Q1-C** (migrations-only) — **ADR обязателен** (меняет QUALITY §4 и стандарт
  персистентности).
- **Q1-B** — ADR не нужен, достаточно записи о переносе в 005b.

### Ответы человека (заполняется на G1)

| Вопрос | Решение |
|---|---|
| Q1 — владелец ORM-схемы | **A.** Минимальный ORM schema-anchor (`paste`) в `Secret/Infrastructure`; `DoctrineSecretRepository` остаётся DBAL; требуется ADR 0003 |
| Q2 — deptrac `Kernel`/`Schedule`/`EventSubscriber` | **A.** Оставить минимальные технические слои; ADR не нужен |
| Q3 — контроллер/view/шаблон | **A.** `SecretCreatedView` только с `expiresAt`; шаблоны остаются `paste/*.twig` до 005b |
| Q4 — судьба тестов | **A.** Удалить `LegacyInteropTest`/`EncryptionServiceTest`; убрать интероп-ветки Sodium; `PasteFlowTest` переписать на `SecretRepository` |
| Q5 — удаление Entity/Repository | **A.** Удалить `Entity\Paste`/`PasteRepository` в 005a (при Q1-A) |
| Q6 — ADR | **Q1-A → ADR 0003** (владение схемой `paste`); deptrac-изменения ADR не требуют |

## Нефункциональные требования и риски

### Нефункциональные требования

- **Поведение неизменно и проверяемо:** маршруты `/`, `/p/{token}`,
  `/created/{token}`; абсолютный формат ссылки; wire-контракты форм; русские
  тексты; статусы `200`/`302`/`404`/`422` (в т.ч. `422` с ошибкой лимита, как
  сейчас: форма, а не HTTP `429`); `no-store`; TTL; лимиты; крипто-формат — без
  изменений.
- **Схема БД неизменна:** ни одной новой/правленой миграции; DDL `paste`
  прежний. Переименование — только 005b.
- **Границы слоёв:** `Domain`/`Application` не импортируют
  Symfony/Doctrine/Sodium; `Presentation` не импортирует `Infrastructure`;
  deptrac 0.
- **Безопасность:** запрещено логировать/рендерить plaintext, `SecretKey`,
  производные ключи, `salt/nonce/ciphertext` сверх легаси; не ослаблять CSRF,
  rate limiting, `no-store`, проверку срока.
- **Тестируемость:** тесты детерминированы (изоляция `cache.app`), не зависят
  от порядка запуска и от предварительно созданного `var/data/test.db`;
  `make qa` воспроизводим локально в Docker.
- **Обратимость:** удаление легаси — механический revert; при Q1-A якорь —
  единственный новый артефакт.

### Риски и меры

| Риск | Влияние | Мера |
|---|---|---|
| Удаление `Entity\Paste` ломает `SchemaTool`/`schema:validate` | CI красный, тесты не создают схему | Q1; при A — schema-anchor; при C — ADR и правка гейта/тестов |
| `doctrine.yaml` mapping на несуществующий `src/Entity` | Падение сборки контейнера | Синхронизировать `config/packages/doctrine.yaml` при удалении каталога |
| `routes.yaml` импорт `../src/Controller/` | Падение загрузки маршрутов | Удалить `controllers`-блок при удалении каталога |
| `services.yaml` excludes на удалённые каталоги | Мусор в DI/ошибки | Синхронизировать excludes |
| Локальный `make test` без миграций | Ложно-зелёные/красные тесты | При A/B — `SchemaTool` продолжает работать; при C — bootstrap схемы миграциями |
| Дрейф ORM-якоря и DBAL-адаптера (A) | `schema:validate`/данные | `schema:validate` + интеграционные тесты; ADR/запись решения |
| Частичное удаление легаси (B) | Цель 005a не достигнута, deptrac не целевой | Явно зафиксировать в plan/notes; полное удаление — 005b |
| `created` рендерит лишние данные/секреты | Утечка крипто-материала | Q3-A: view-модель только с `expiresAt`; AC6/AC11 |
| Потеря легаси-интероп-покрытия | Регрессия совместимости | Сохранить round-trip Sodium и интеграционные тесты репозитория; интероп не нужен после удаления легаси |
| Рост/неконтролируемый `@phpstan-ignore` | Ослабление гейта | Не добавлять без причины и даты; удаление тестов снижает счётчик |

## Definition of Done (DoD) для задачи

1. `make qa` (cs-check, PHPStan L10, deptrac, PHPUnit) зелёный; `make coverage`
   ≥ 90% по `src/*/{Domain,Application}`.
2. `doctrine:schema:validate --env=test` зелёный (Q1-A/B) либо гейт изменён по
   утверждённому ADR (Q1-C).
3. Миграции не изменены и не добавлены; DDL `paste` не тронут.
4. Маршруты, URL, wire-контракты форм, тексты, коды, `no-store`, лимиты, TTL и
   крипто-формат неизменны; `created` для истёкшего Secret по-прежнему `200`.
5. Production-код не содержит удаляемых легаси-классов (Q1-A/C) либо
   `Entity`/`Repository` оставлены только как явный schema/test-anchor (Q1-B).
6. `deptrac.yaml` — целевой набор без бизнес-легаси, 0 нарушений.
7. `docs/UBIQUITOUS_LANGUAGE.md` не изменён (новых терминов нет); при Q1-A/C или
   смене стандарта оформлен ADR.
8. `docs/task/005-remove-legacy-paste/{requirements,plan,review}.md` и
   `ROADMAP.md` актуальны; `005b` (`006`) остаётся единственным местом для
   переименования таблицы и миграции.
