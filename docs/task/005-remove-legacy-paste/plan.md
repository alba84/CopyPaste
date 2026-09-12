# План: 005-remove-legacy-paste

> Статус: **G2 пройден.** Решения G1 (Q1-A … Q6-A) обязательны; ADR 0003
> (`accepted`) утверждён. Объём — 005a: перенос `created` в `Secret` и удаление
> легаси `App\*` **без изменения схемы БД и наблюдаемого поведения**. Миграций
> нет; переименование `paste → secret` — 005b (`006`).

## Связь с требованиями

Задача завершает миграцию `App\*` (002–004) и доводит проект до структуры
`docs/ARCHITECTURE.md` §3–5, §9.3: сценарий «Ссылка готова» (`/created/{token}`)
обслуживается модулем `Secret`, легаси-бизнес-классы и их deptrac-слои удалены,
схема БД и всё наблюдаемое поведение неизменны. Новых доменных терминов нет
(глоссарий не меняется).

Решения G1 разворачиваются так:

| G1 | Как реализуется в плане | Шаги |
|---|---|---|
| Q1-A — минимальный ORM schema-anchor в `Secret/Infrastructure` + ADR 0003 | `PasteSchemaAnchor` (attribute, `paste`, поля/индекс как у легаси); mapping `App` заменяется на `SecretSchema`; DBAL-адаптер не меняется | Ш2 |
| Q2-A — оставить технические слои `Kernel`/`Schedule`/`EventSubscriber` | Слои и правила сохраняются; ADR на deptrac не нужен | Ш4 |
| Q3-A — `SecretCreatedController` + `SecretCreatedView` только с `expiresAt`; шаблон остаётся `paste/created.html.twig` | Контроллер на `SecretRepository::findByToken`; view-модель без payload; путь шаблона сохранён | Ш1 |
| Q4-A — `LegacyInteropTest`/`EncryptionServiceTest` удалить; интероп-ветки Sodium убрать; `PasteFlowTest` переписать на `SecretRepository` | Удаление/переписывание тестов без смены ожиданий статусов/текстов | Ш2, Ш3 |
| Q5-A — `Entity\Paste`/`PasteRepository` удалить в 005a | Удаляются вместе с маппингом в Ш2 | Ш2 |
| Q6-A — ADR 0003 для владения схемой; deptrac-ADR не нужен | `docs/adr/0003-paste-schema-anchor.md` (proposed) | Ш0/Ш5 |

Соответствие критериям приёмки:

| AC | Где закрывается |
|---|---|
| AC1 | Ш1 (`SecretCreatedController` + view + шаблон), `SecretCreatedFlowTest`, `SecretWiringTest` (route) |
| AC2 | Ш1 `SecretCreatedFlowTest` (unknown token → 404) |
| AC3 | Ш1 `SecretCreatedFlowTest` (expired → 200, без фильтра срока) |
| AC4 | Ш2/Ш3 (удаление классов), `LegacyRemovalTest`, `config/services_test.yaml` отсутствует |
| AC5 | Ш2 — существующие проверки `/`, `/p/{token}` сохранены, меняется только seeding/проверки через порт |
| AC6 | Ш1 (тонкий контроллер + view-only-expiresAt), leak-тест в `SecretCreatedFlowTest` |
| AC7 | Ш4 (`deptrac.yaml` — целевой ruleset, 0 нарушений) |
| AC8 | Ш2 (якорь + mapping), `PasteSchemaAnchorTest`, `schema:validate`; `migrations/` не тронут |
| AC9 | Ш2/Ш3 (переписанные/удалённые тесты), негативный `LegacyRemovalTest` |
| AC10 | Ш5 (`make qa`, `make coverage` ≥ 90% по Domain/Application, без новых ignore) |
| AC11 | Ш1 leak-тест; существующие security-проверки не ослаблены |
| AC12 | ADR 0003 + `ROADMAP.md`; `UBIQUITOUS_LANGUAGE.md` не изменён |

## Затрагиваемые модули и слои

- **`Secret/Presentation/Http`**: новый `SecretCreatedController` (+ маршрут
  `app_paste_created`), `SecretCreatedView`. `RevealSecretController` и его
  маршрут `app_paste_view` не трогаются.
- **`Secret/Infrastructure/Persistence/Doctrine/Schema`**: новый
  `PasteSchemaAnchor` — только ORM-метаданные.
- **`Secret/Domain`, `Secret/Application`**: без изменений; порт
  `SecretRepository` достаточен (`findByToken`), новых методов не требуется.
- **Удаляется (production)**: `src/Controller/PasteController.php`,
  `src/Entity/Paste.php`, `src/Repository/PasteRepository.php`,
  `src/Dto/{CreatePasteData,UnlockPasteData,EncryptedPayload}.php`,
  `src/Form/{CreatePasteType,UnlockPasteType}.php`,
  `src/Service/EncryptionService.php`, `config/services_test.yaml`.
- **Конфигурация**: `config/routes.yaml` (убрать блок `controllers`),
  `config/packages/doctrine.yaml` (mapping), `config/services.yaml` (excludes),
  `deptrac.yaml` (целевой ruleset). `rate_limiter.yaml`, `messenger.yaml`,
  `doctrine_migrations.yaml` — без изменений.
- **`templates/paste/created.html.twig`**: переменная `paste` → `created`
  (view-модель); путь и разметка/тексты сохранены.
- **Не удаляется**: `App\Kernel`, `App\Schedule`, `App\EventSubscriber\SQLitePragmaSubscriber`,
  `templates/paste/*`, маршруты/имена `app_paste_view`, `app_paste_created`,
  команда `app:paste:purge`.
- **Инфраструктура тестов**: см. тест-план.

### Структура после 005a

```text
src/
├── Kernel.php
├── Schedule.php
├── EventSubscriber/SQLitePragmaSubscriber.php
├── Crypto/{Domain,Infrastructure}
├── Shared/                                  # зарезервирован, классов нет
└── Secret/
    ├── Domain/...                            # без изменений
    ├── Application/...                       # без изменений
    ├── Infrastructure/
    │   ├── Persistence/Doctrine/
    │   │   ├── SecretRecord.php              # DBAL-модель
    │   │   ├── SecretRecordMapper.php
    │   │   ├── DoctrineSecretRepository.php
    │   │   └── Schema/PasteSchemaAnchor.php  # НОВЫЙ ORM-якорь (только schema tooling)
    │   └── Token/RandomTokenGenerator.php
    └── Presentation/
        ├── Http/
        │   ├── CreateSecretController.php
        │   ├── RevealSecretController.php
        │   ├── SecretCreatedController.php   # НОВЫЙ (/created/{token})
        │   ├── SecretCreatedView.php         # НОВЫЙ (только expiresAt)
        │   ├── Form/...
        │   └── Input/...
        └── Console/PurgeExpiredSecretsCommand.php
```

## Изменение модели/контрактов (агрегаты, VO, порты)

**Доменная модель, VO, события и порты не меняются.** `SecretRepository` уже
предоставляет `findByToken(Token): ?Secret` без фильтра срока — этого достаточно
и для `created` (легаси `findOneBy` срок не фильтровал, AC3). Новых методов
порта и новых use case не вводится.

### ORM schema-anchor (`PasteSchemaAnchor`)

Метаданные побайтово эквивалентны удаляемой `App\Entity\Paste`, кроме
`repositoryClass` (репозиторий не нужен):

```php
namespace App\Secret\Infrastructure\Persistence\Doctrine\Schema;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'paste'), ORM\Index(name: 'idx_paste_expires_at', columns: ['expires_at'])]
class PasteSchemaAnchor
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    public string $token;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $hint = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $salt = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $nonce = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $ciphertext = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $expiresAt;
}
```

- Класс технический, без доменного значения, классовым глоссарием не покрывается.
- Публичные свойства (а не private + getters) — осознанный выбор: у якоря нет
  поведения, а публичный контракт не порождает замечаний PHPStan об
  uninitialized/unused private. `readonly` не используется — ORM пишет свойства.
- Рантайм её не инстанцирует и не гидратирует; используется только
  `getAllMetadata()`/`SchemaTool`/`SchemaValidator`.

### Mapping (`config/packages/doctrine.yaml`)

```yaml
orm:
    # ... остальное без изменений ...
    auto_mapping: true
    mappings:
        SecretSchema:
            type: attribute
            is_bundle: false
            dir: '%kernel.project_dir%/src/Secret/Infrastructure/Persistence/Doctrine/Schema'
            prefix: 'App\Secret\Infrastructure\Persistence\Doctrine\Schema'
            alias: SecretSchema
```

Блок `App` → `src/Entity` удаляется. Этим:

- `SchemaTool::getAllMetadata()` возвращает ровно метаданные `paste`
  (`dropSchema`/`createSchema` снова работают; локальный `make test` без миграций
  создаёт таблицу);
- `SchemaValidator::schemaInSyncWithMetadata()` сравнивает БД с якорем и даёт
  `mapping OK`/`schema in sync`, exit `0` (AC8);
- mapping не ссылается на удалённый `src/Entity` (нет падения контейнера).

### Presentation `created`

```php
// Secret/Presentation/Http/SecretCreatedView.php
final readonly class SecretCreatedView
{
    public function __construct(public DateTimeImmutable $expiresAt)
    {
    }
}
```

```php
// Secret/Presentation/Http/SecretCreatedController.php
final class SecretCreatedController extends AbstractController
{
    #[Route('/created/{token}', name: 'app_paste_created', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function __invoke(string $token, SecretRepository $secretRepository): Response
    {
        $secret = $secretRepository->findByToken(new Token($token));
        if (null === $secret) {
            throw $this->createNotFoundException();
        }

        return $this->render('paste/created.html.twig', [
            'created' => new SecretCreatedView($secret->expiresAt()),
            'paste_url' => $this->generateUrl('app_paste_view', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }
}
```

- Тонкий контроллер: чтение порта + view-модель + рендер. Нет QueryBuilder/SQL,
  TTL, Sodium, `persist/flush`, `Clock` (AC6).
- Срок **не** проверяется (AC3): у легаси `created` был `findOneBy` без срока.
- В шаблон не попадают `hint/plaintext/salt/nonce/ciphertext` (только
  `SecretCreatedView.expiresAt` и `paste_url`) (AC6/AC11).
- `templates/paste/created.html.twig`: `{{ paste.expiresAt|date('d.m.Y, H:i') }}`
  → `{{ created.expiresAt|date('d.m.Y, H:i') }}`; `h1`, `#paste-url`, разметка,
  тексты и путь шаблона сохранены.
- Маршрут: имя `app_paste_created`, путь, requirement и `GET` — как в легаси
  (наблюдаемые имена не переименовываются, out of scope).

`SecretCreatedView` — DTO представления, создаётся через `new` (не сервис).
Чтобы исключить любые вопросы авторегистрации, файл исключается из `App\:` в
`config/services.yaml`.

## Тест-план

Расположение зеркалит `src` (`docs/CONVENTIONS.md` §7). Ожидания поведения
(статусы, тексты, URL, wire-контракты, `no-store`, лимиты) не меняются — меняются
только способ seeding/проверки (через `SecretRepository`/`Cryptography`/DBAL) и
состав негативных проверок.

- **unit:**
  - `tests/Unit/Service/EncryptionServiceTest.php` — **удаляется** (Q4-A).
  - Существующие unit Domain/Application не трогаются; новых unit-классов для
    `created` не требуется (там нет ветвлений кроме null/не null).
  - `@phpstan-ignore` в новых тестах не добавляем: `SecretCreatedFlowTest` и
    `PasteSchemaAnchorTest` строятся на локальных переменных/хелперах без свойств
    `setUp()` (паттерн 004). Счётчик ignore снижается (удаляются 2 тест-класса,
    `SodiumCryptographyTest` теряет legacy-свойство).
- **application:**
  - `tests/Unit/Secret/Application/RevealSecret/RevealSecretHandlerTest.php` не
    меняется; кейс пустого plaintext (`testEmptyPlaintextIsRevealedAsSuccess`)
    остаётся покрытием этой ветки после удаления легаси-интеропа.
- **integration:**
  - `tests/Integration/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepositoryTest.php`:
    убрать `use App\Entity\Paste;`/`use App\Repository\PasteRepository;` и
    `testSaveIsReadableByLegacyRepository` (его роль берут
    `testSavePersistsRowWithExpectedColumns` + новый anchor-тест). Остальные
    кейсы без смены ожиданий.
  - **новый** `tests/Integration/Secret/Infrastructure/Persistence/Doctrine/PasteSchemaAnchorTest.php`
    (`KernelTestCase`, схема через `SchemaTool`):
    - `testOrmMetadataDescribesPasteTableAndColumns()` — таблица `paste`, колонки
      `id/token/hint/salt/nonce/ciphertext/created_at/expires_at`, присутствует
      индекс `idx_paste_expires_at`;
    - `testSchemaIsInSyncWithMetadata()` —
      `SchemaValidator::schemaInSyncWithMetadata()` = true и пустой
      `getUpdateSchemaSql()` (эквивалент `doctrine:schema:validate`);
    - `testPhysicalTableColumnsMatchOrmMetadata()` — после `createSchema()` имена
      колонок `PRAGMA table_info(paste)` совпадают с
      `ClassMetadata::getColumnNames()` (связывает ORM-якорь с таблицей, в
      которую пишет DBAL-адаптер — защита от дрейфа двух представлений).
  - `tests/Integration/Crypto/Infrastructure/Sodium/SodiumCryptographyTest.php`:
    убрать `App\Dto\EncryptedPayload as LegacyEncryptedPayload`,
    `App\Service\EncryptionService` и три legacy-интероп-кейса
    (`testNewEncryptionIsDecryptableByLegacyService`,
    `testLegacyEncryptionIsDecryptableByNewAdapter`,
    `testEmptyPlaintextEncryptedByLegacyServiceIsDecryptedAsSuccess`). Остаются
    round-trip, длины salt/nonce, неверный ключ, уникальность.
  - `tests/Integration/Secret/LegacyInteropTest.php` — **удаляется** (Q4-A):
    после удаления легаси интероп не с чем проверять; чтение/запись остаются в
    `DoctrineSecretRepository*` и `PasteSchemaAnchorTest`.
- **functional:**
  - **новый** `tests/Functional/SecretCreatedFlowTest.php`:
    - `testCreatedPageContainsAbsoluteRevealLinkAndFormattedExpiry()` — валидный
      `Secret`; `GET /created/{T}` → 200, `h1` «Ссылка готова», `#paste-url` =
      абсолютный `/p/{T}`, уведомление содержит `expiresAt` в `d.m.Y, H:i` (AC1);
    - `testUnknownTokenReturnsNeutral404()` — валидный по формату, но
      отсутствующий токен → 404, детали не раскрываются (AC2);
    - `testExpiredSecretStillRendersCreatedPage()` — `expiresAt` в прошлом → 200
      с тем же содержимым (AC3);
    - `testCreatedPageDoesNotLeakHintOrPlaintext()` — `Secret` с уникальной
      подсказкой/текстом: тело не содержит подсказку/plaintext (AC6/AC11).
  - `tests/Functional/PasteFlowTest.php`: seeding истёкшей записи через
    `SecretRepository` (`Secret::reconstitute`) вместо `EntityManager::persist` и
    `App\Entity\Paste`; убрать `App\Dto\EncryptedPayload`. Ожидания и ассерты не
    меняются; `EntityManagerInterface` остаётся только для `SchemaTool`.
  - `tests/Functional/CreateSecretFlowTest.php`: `PasteRepository` →
    `SecretRepository`, `EncryptionService` → `Cryptography`; hint/expiry
    проверяются через агрегат; счётчик записей лимитера — через
    `Connection::fetchOne('SELECT COUNT(*) FROM paste')`; тест
    `testCreatedSecretIsReadableAndDecryptableByLegacyPath` переписывается на
    `testCreatedSecretIsReadableAndDecryptableThroughSecretRepository`
    (`Cryptography::decrypt` → `DecryptedPlaintext`). Ожидания (302, 422, тексты,
    absolute link) не меняются.
  - `tests/Functional/RevealSecretFlowTest.php`: `persistLegacyPaste` →
    `persistSecret` (Cryptography + `SecretRepository`); счётчики — через DBAL;
    `testRevealCanBeRepeatedAndLeavesRecordUntouched` — через `findByToken` +
    count. Остальные кейсы (404/422/лимитер/no-store/пустой ключ) без смены
    ожиданий.
    - **Удаляется** `testEmptyPlaintextIsRevealedAsSuccessOverHttp`: он seeding'ил
      запись, зашифрованную только легаси `EncryptionService` (новый `encrypt`
      отвергает `''` по инварианту `Plaintext`). Ветка «`''` — успех» остаётся
      покрытой application-тестом `RevealSecretHandlerTest::testEmptyPlaintextIsRevealedAsSuccess`.
      Явное изменение состава тестов, фиксируется для G2.
  - `tests/Functional/PurgeExpiredSecretsCommandTest.php`:
    `testLegacyPurgeCommandAndRepositoryMethodAreGone` → `...AreGone` с
    `assertFalse(class_exists('App\\Command\\PurgeExpiredPastesCommand'))` и
    `assertFalse(class_exists('App\\Repository\\PasteRepository'))`; убрать
    `use App\Repository\PasteRepository;`. Остальное без изменений.
  - **новый** `tests/Functional/LegacyRemovalTest.php` (структурный guard, без
    kernel): `assertFalse(class_exists(...))` для `App\Controller\PasteController`,
    `App\Dto\{CreatePasteData,UnlockPasteData,EncryptedPayload}`,
    `App\Form\{CreatePasteType,UnlockPasteType}`, `App\Service\EncryptionService`,
    `App\Entity\Paste`, `App\Repository\PasteRepository`; `assertFileDoesNotExist`
    для `config/services_test.yaml` (AC4).
  - `tests/Functional/SecretWiringTest.php`: добавить
    `testCreatedRouteKeepsLegacyNameAndUrl()` — `generate('app_paste_created',
    ['token' => 'a'×32]) === '/created/'.str_repeat('a',32)`, requirement
    `[a-f0-9]{32}` и `GET`; существующие тесты не меняются.

## Пошаговый план реализации

Каждый шаг — отдельный обратимый коммит; после каждого `make qa`,
`make coverage` и `make console doctrine:schema:validate --env=test` зелёные.
Тесты идут в коммите с соответствующим кодом.

- **Ш0. Baseline.** `make up`, `make qa`, `make coverage`,
  `make console doctrine:schema:validate --env=test`, `make console list`
  (зафиксировать маршруты/команду). Зафиксировать `@phpstan-ignore` =
  15. Коммита нет. ADR 0003 создан архитектором (`proposed`, вне коммита кода).

- **Ш1. Перенос `created` в `Secret`.** Добавить
  `SecretCreatedController`/`SecretCreatedView`; поправить переменную шаблона;
  удалить `src/Controller/PasteController.php`; убрать блок `controllers` из
  `config/routes.yaml`; исключить `SecretCreatedView.php` в
  `config/services.yaml`; добавить `SecretCreatedFlowTest` и route-тест в
  `SecretWiringTest`. Легаси-entity/repo/DTO/Form/Service пока остаются, чтобы
  остальные тесты были зелёными. Коммит:
  `refactor: serve created page from Secret module`.

- **Ш2. ORM schema-anchor и удаление ORM-легаси.** Добавить
  `Schema/PasteSchemaAnchor`; в `config/packages/doctrine.yaml` заменить mapping
  `App` на `SecretSchema`; в `config/services.yaml` убрать exclude `../src/Entity/`
  и добавить exclude `Schema/`; удалить `src/Entity/Paste.php`,
  `src/Repository/PasteRepository.php`; переписать
  `DoctrineSecretRepositoryTest`, `PasteFlowTest`, `CreateSecretFlowTest`,
  `RevealSecretFlowTest`, `PurgeExpiredSecretsCommandTest`; удалить
  `LegacyInteropTest`; убрать легаси-ветки `SodiumCryptographyTest`; добавить
  `PasteSchemaAnchorTest`. Коммит:
  `refactor: replace legacy Paste ORM mapping with schema anchor`.

- **Ш3. Удаление мёртвого легаси-кода.** Удалить
  `src/Dto/{CreatePasteData,UnlockPasteData,EncryptedPayload}.php`,
  `src/Form/{CreatePasteType,UnlockPasteType}.php`,
  `src/Service/EncryptionService.php`, `config/services_test.yaml`,
  `tests/Unit/Service/EncryptionServiceTest.php`; добавить
  `LegacyRemovalTest`. Коммит: `refactor: remove dead legacy paste layer`.

- **Ш4. Целевой deptrac.** Удалить слои/правила
  `Controller/Form/Dto/Entity/Repository/Service/Command`; сохранить
  `Kernel/Schedule/EventSubscriber` и их правила (Q2-A); обновить
  `docs/QUALITY.md` §2.2 (технические слои + правила). `make deptrac` → 0/0.
  Коммит: `chore: drop legacy deptrac layers`.

- **Ш5. Финальная верификация и документы.** `make qa` (cs-check, PHPStan L10 —
  0 без baseline, deptrac 0, PHPUnit), `make coverage`
  (Domain/Application ≥ 90%), `doctrine:schema:validate --env=test`. Перевести
  ADR 0003 в `accepted`, обновить `docs/ARCHITECTURE.md` §5 и
  `docs/QUALITY.md` §2.2 (после одобрения на G2), `docs/task/ROADMAP.md`
  (`005` → `[x]`, журнал; `006` → `[ ]`). Коммит:
  `docs: finalize 005 remove-legacy-paste artifacts`.

## Миграции БД

**Нет.** `migrations/` не изменяется/не дополняется: `Version20260909000000`
остаётся единственной миграцией, DDL `paste` (колонки, unique-индекс на `token`,
индекс `idx_paste_expires_at`) не меняется. Владение ORM-метаданными переходит от
`App\Entity\Paste` к `PasteSchemaAnchor`, чьи метаданные эквивалентны, поэтому
`doctrine:schema:validate --env=test` остаётся зелёным без правки гейта.
Переименование `paste → secret` и возможный переход на migrations-only — 005b
(`006`) с отдельным ADR/гейтом.

## Влияние на стандарты (ADR, если нужно)

**Нужен ADR 0003** (приложен, статус `proposed`): перенос владения ORM-схемой
`paste` в минимальный schema-anchor внутри `Secret/Infrastructure` и сосуществование
якоря с DBAL-адаптером. ADR дополняет ADR 0002, а не заменяет его. Это изменение
архитектурного стандарта (§5 ARCHITECTURE), поэтому перед реализацией требуется
гейт **G2 с решением человека**. После `accepted` обновляются
`docs/ARCHITECTURE.md` §5 и (в части технических слоёв) `docs/QUALITY.md` §2.2.

**Deptrac-изменения ADR не требуют** (Q2-A/Q6-A): целевой набор уже зафиксирован
ADR 0002/QUALITY §2.2; удаление легаси-слоёв и сохранение технических
`Kernel/Schedule/EventSubscriber` — исполнение принятого решения. Добавление этих
трёх технических слоёв в QUALITY §2.2 — синхронизация документа с Q2-A, не новый
стандарт. Доменные термины не вводятся; `docs/UBIQUITOUS_LANGUAGE.md` не меняется.

## Риски и обратимые шаги

| Риск | Митигация | Обратимость |
|---|---|---|
| Схема двух представлений (`paste` ORM-якорь + DBAL SQL) разойдётся | `schema:validate` в CI + `PasteSchemaAnchorTest` (`schemaInSyncWithMetadata`, сверка `PRAGMA table_info` с метаданными) + интеграционные тесты репозитория | Правка якоря/адаптера одной парой |
| Якорь метаданными отличается от легаси-сущности (колонки/индекс/имя) | Якорь повторяет mapping 1:1 (кроме `repositoryClass`); `schema:validate` и `SchemaTool` падают/краснеют при расхождении; тест колонок/индекса | `git revert` Ш2 |
| Mapping ссылается на удалённый `src/Entity` или пустой каталог | Mapping и удаление каталога — в одном коммите Ш2; `SchemaTool` создаёт `paste` в каждом тесте | `git revert` Ш2 |
| `routes.yaml` импортирует удалённый `src/Controller/` | Блок `controllers` удаляется в Ш1 одновременно с `PasteController`; `app_paste_created` обслуживает `SecretCreatedController` | `git revert` Ш1 |
| Дублирование route name `app_paste_created` | Старый контроллер удаляется в том же коммите; route-тест `SecretWiringTest` проверяет ровно один маршрут | `git revert` Ш1 |
| `config/services.yaml` ссылается на удалённые каталоги / авторегистрирует якорь | Excludes синхронизируются в Ш2/Ш3; `Schema/` и `SecretCreatedView.php` исключены; `make qa` собирает контейнер | `git revert` шага |
| Утечка payload/hint в `created` | View-модель только с `expiresAt`; leak-тест проверяет отсутствие подсказки/plaintext в теле | Правка view |
| Потеря HTTP-покрытия пустого plaintext | Ветка остаётся в `RevealSecretHandlerTest`; удалённый HTTP-кейс seeding'ился только легаси-сервисом, недостижимым после его удаления (фиксируется для G2) | Вернуть фикстуру фиксированных байтов |
| Рост `@phpstan-ignore` | Новые тест-классы без свойств `setUp()`; удаляются 2 класса и legacy-свойство → счётчик снижается | Локально |
| Целевой deptrac «непокрытые» технические классы | `Kernel/Schedule/EventSubscriber` остаются слоями с текущими правилами (Q2-A); `make deptrac` → 0/0 | Правка deptrac |
| Расползание в 005b (переименование таблицы) / Access | Явный out of scope: `migrations/`, `paste`, имена команд/маршрутов не меняются | Ревью AC8/AC12 |
| Падение сборки из-за авторегистрации view/anchor как сервисов | Файлы исключены из `App\:`; DI не трогается; `SecretCreatedView` создаётся через `new` | Правка excludes |

## Definition of Done для задачи

- [ ] Q1–Q6 реализованы как A: `created` обслуживает `Secret`; `Entity\Paste`/
      `Repository\PasteRepository` и прочий легаси удалены; schema-anchor владеет
      ORM-метаданными; deptrac — целевой набор.
- [ ] `/created/{token}` (`app_paste_created`, requirement `[a-f0-9]{32}`, GET)
      даёт прежние 200/404 и разметку; для истёкшего `Secret` — 200 (AC1–AC3).
- [ ] В шаблон не передаются `hint/plaintext/salt/nonce/ciphertext`; контроллер
      тонкий (AC6, AC11).
- [ ] `deptrac.yaml` — целевой набор без `Controller/Form/Dto/Entity/Repository/
      Service/Command`; `make deptrac` 0 нарушений (AC7).
- [ ] `migrations/` не изменён; DDL `paste` прежний;
      `doctrine:schema:validate --env=test` — mapping OK, schema in sync, exit 0;
      `SchemaTool` создаёт схему из якоря (AC8).
- [ ] Тесты: `LegacyInteropTest`/`EncryptionServiceTest` удалены; legacy-ветки
      Sodium убраны; seeding/проверки через `SecretRepository`/`Cryptography`;
      добавлены `SecretCreatedFlowTest`, `PasteSchemaAnchorTest`,
      `LegacyRemovalTest`; негативные `class_exists` зелёные; нет ссылок на
      удалённые классы (AC4, AC9).
- [ ] Существующие `/`, `/p/{token}` проверки (wire-контракты, статусы, тексты,
      `no-store`, лимиты) проходят без смены ожиданий (AC5).
- [ ] `make qa` зелёный (cs-check, PHPStan L10 — 0 без baseline, deptrac 0,
      PHPUnit); `make coverage` — `src/*/{Domain,Application}` ≥ 90%; новых
      `@phpstan-ignore` без причины/даты нет (AC10).
- [ ] Секреты/ключи/salt/nonce/plaintext не логируются и не рендерятся сверх
      легаси; CSRF/rate limiting/`no-store`/срок не ослаблены (AC11).
- [ ] ADR 0003 оформлен и переведён в `accepted` после G2; `ROADMAP.md` обновлён;
      `docs/UBIQUITOUS_LANGUAGE.md` не изменён; `005b` (`006`) остаётся местом для
      переименования таблицы и миграции (AC12).
- [ ] Артефакты — в `docs/task/005-remove-legacy-paste/`.
