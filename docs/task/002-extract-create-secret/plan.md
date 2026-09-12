# План: 002-extract-create-secret

> Статус: **ожидает G2.** Решения G1 (Q1–Q6, все вариант A) приняты как
> обязательные. Объём — только извлечение сценария создания; Reveal, Purge и
> Access не мигрируют.

## Связь с требованиями

Задача извлекает сценарий `CreateSecret` из `App\Controller\PasteController`
в целевой модуль `Secret` по `docs/ARCHITECTURE.md` §9.3 и фиксирует эталон
структуры для задач 003/004/005. Внешнее поведение неизменно.

Решения G1 разворачиваются в плане так:

| G1 | Как реализуется в плане | Шаги |
|---|---|---|
| Q1-A — persistence-модель + маппер на таблицу `paste`, легаси `Paste` — единственный ORM-класс | `SecretRecord` (не ORM) + `SecretRecordMapper` + `DoctrineSecretRepository` поверх `Doctrine\DBAL\Connection`; второго ORM-маппинга нет → `doctrine:schema:validate` не трогается | Ш7 |
| Q2-A — имя таблицы `paste` не менять | Адаптер пишет/читает ровно в `paste`; миграций нет | Ш7, «Миграции БД» |
| Q3-A — крипто-формат без изменений | `SodiumCryptography` копирует алгоритм `EncryptionService` (Argon2id + secretbox, ops/mem из env); интеграционный тест интеропа в обе стороны | Ш6 |
| Q4-A — добавить целевые слои `Secret_*`/`Crypto_*`/`Shared`, легаси `App\*` as-is | Новые слои и правила в `deptrac.yaml`; легаси-правила не меняются | Ш1 |
| Q5-A — классы Secret, но wire-контракт `create_paste` сохранён | `CreateSecretType::getBlockPrefix() === 'create_paste'`, `csrf_token_id: create_paste`, поля `text`/`hint`/`secret` | Ш9 |
| Q6-A — `created` остаётся в легаси, rate limiting в Presentation | `PasteController` теряет только `create`; `created`/`view` остаются на `PasteRepository`; лимитер `create_paste` вызывается в новом контроллере | Ш9 |

Соответствие критериям приёмки:

| AC | Где закрывается |
|---|---|
| AC1, AC5, AC6 | Ш9, Ш10 (functional) |
| AC2, AC13 | Ш3, Ш8 (unit domain/application) |
| AC3, AC4 | Ш6, Ш7 (integration), Ш9 (functional «новое создание → легаси Reveal») |
| AC7, AC8 | Ш2 (Hint VO), Ш9, Ш10 (functional) |
| AC9 | Ш9 (ревью контроллера) |
| AC10 | Ш1 + `make deptrac` |
| AC11 | Ш9, Ш10 (существующий `PasteFlowTest` без смены ожиданий) |
| AC12 | Ш2–Ш8 (unit вместе с кодом) + `make coverage` |
| AC14 | Ш4, Ш7 (unit/integration уникальности и формата) |
| AC15 | Ш11 (`make qa`, `make coverage`) |

### Уточнение противоречия Q6-A с формулировкой in-scope №7/№8

In-scope №8 говорит «у `PasteController` удаляются `create` и `created`», а
утверждённая на G1 таблица Q6-A — «`created` остаётся в легаси-контроллере на
`PasteRepository`». Как более позднее и явное решение G1 принимается **Q6-A**:
`PasteController` теряет только `create`; `created` и `view` остаются. Новый
`Secret/Presentation` владеет только маршрутом `app_home` (`/`). Маршрут
`app_paste_created` (`/created/{token}`) сохраняет имя и URL, но обслуживается
легаси-контроллером. Это отмечено как риск для подтверждения на G2.

## Затрагиваемые модули и слои

- **Новые**: `App\Secret\{Domain,Application,Infrastructure,Presentation}`,
  `App\Crypto\{Domain,Infrastructure}`. Слой `App\Shared` в deptrac заводится,
  но **классов в 002 не содержит** (нет реальной общей необходимости;
  `Clock` — это `Psr\Clock\ClockInterface`).
- **Легаси (минимальное касание)**: `App\Controller\PasteController` — удаляется
  `create` и неиспользуемые импорты; `created`/`view` не переписываются.
  Остальные легаси-классы не трогаются.
- **Конфигурация**: `deptrac.yaml`, `config/routes.yaml`, `config/services.yaml`.
  `config/packages/doctrine.yaml` **не меняется** (новых ORM-маппингов нет).
- **Инфраструктура тестов**: `tests/Unit/Secret/...`, `tests/Unit/Crypto/...`,
  `tests/Integration/...`, `tests/Functional/...`, `tests/Support/...`.

### Проектируемая структура каталогов

```text
src/
├── Secret/
│   ├── Domain/
│   │   ├── Model/
│   │   │   ├── Secret.php
│   │   │   ├── Token.php
│   │   │   ├── Ttl.php
│   │   │   ├── Hint.php
│   │   │   ├── Plaintext.php
│   │   │   ├── SecretKey.php
│   │   │   └── EncryptedPayload.php
│   │   ├── Event/
│   │   │   ├── SecretDomainEvent.php
│   │   │   └── SecretCreated.php
│   │   ├── Exception/
│   │   │   ├── SecretException.php
│   │   │   ├── InvalidToken.php
│   │   │   ├── InvalidTtl.php
│   │   │   ├── InvalidHint.php
│   │   │   ├── InvalidPlaintext.php
│   │   │   ├── InvalidSecretKey.php
│   │   │   ├── InvalidEncryptedPayload.php
│   │   │   └── TokenGenerationFailed.php
│   │   ├── Repository/
│   │   │   └── SecretRepository.php
│   │   └── Service/
│   │       ├── TokenGenerator.php
│   │       └── UniqueTokenGenerator.php
│   ├── Application/
│   │   └── CreateSecret/
│   │       ├── CreateSecret.php
│   │       ├── CreateSecretHandler.php
│   │       └── CreateSecretResult.php
│   ├── Infrastructure/
│   │   ├── Persistence/Doctrine/
│   │   │   ├── SecretRecord.php
│   │   │   ├── SecretRecordMapper.php
│   │   │   └── DoctrineSecretRepository.php
│   │   └── Token/RandomTokenGenerator.php
│   └── Presentation/
│       └── Http/
│           ├── CreateSecretController.php
│           ├── Form/CreateSecretType.php
│           └── Input/CreateSecretInput.php
└── Crypto/
    ├── Domain/
    │   ├── Cryptography.php
    │   ├── DecryptionResult.php
    │   ├── DecryptedPlaintext.php
    │   └── DecryptionFailed.php
    └── Infrastructure/Sodium/SodiumCryptography.php
```

## Изменение модели/контрактов (агрегаты, VO, порты)

Все VO — `final readonly`, самовалидирующиеся в конструкторе/фабрике, бросают
доменное исключение под `Secret/Domain/Exception`. Домен не импортирует
Symfony/Doctrine/Sodium (длины BLOB задаются собственными константами VO, а не
константами Sodium).

### Агрегат `Secret` (`Secret/Domain/Model/Secret.php`)

Поля: `Token $token`, `?Hint $hint`, `EncryptedPayload $payload`,
`DateTimeImmutable $createdAt`, `DateTimeImmutable $expiresAt`. Признаки
«одноразовый»/«просмотрен» **не добавляются** — их нет в схеме `paste`, а Reveal
вне объёма 002.

- `public static function create(Token $token, ?Hint $hint, EncryptedPayload $payload, DateTimeImmutable $createdAt, Ttl $ttl): self` — единственный способ создания; вычисляет `expiresAt = $createdAt->add(...)` через `Ttl`; фиксирует `SecretCreated` во внутреннем списке.
- Доменные геттеры без префикса `get`: `token()`, `hint()`, `payload()`, `createdAt()`, `expiresAt()`.
- `public function isExpiredAt(DateTimeImmutable $now): bool { return $this->expiresAt <= $now; }` — для будущего Reveal.
- `public function pullDomainEvents(): array` — возвращает и очищает зафиксированные события (**публикации в шину нет**, AC13). Тип — `list<SecretDomainEvent>`.

### Value Objects

| VO | Инварианты | API |
|---|---|---|
| `Token` | `[a-f0-9]{32}` (криптостойкая случайность, совместим с requirement маршрута) | `__construct(string $value)`, `value` |
| `Ttl` | `days >= 1` | `fromDays(int): self`, `days`, `expiresAtFrom(DateTimeImmutable): DateTimeImmutable` (`add(new DateInterval('P{n}D'))`) |
| `Hint` | `null`/пустая после `trim()` → «подсказки нет»; иначе исходная строка сохраняется без обрезки; `mb_strlen <= 255` | `fromUserInput(?string): ?self`, `value` |
| `Plaintext` | непустой; `mb_strlen <= 100000` | `fromString(string): self`, `value` |
| `SecretKey` | непустой; `mb_strlen <= 255` | `fromString(string): self`, `value` |
| `EncryptedPayload` | `strlen(salt) === SALT_BYTES(16)`, `strlen(nonce) === NONCE_BYTES(24)`, непустой ciphertext | `__construct(string $salt, string $nonce, string $ciphertext)` |

`Hint::fromUserInput()` возвращает `?self` — это не «голый null из глубины», а
явная доменная альтернатива «подсказки нет» (AC7). Длины в `EncryptedPayload`
задаются константами VO; тест сверяет их с `SODIUM_CRYPTO_PWHASH_SALTBYTES` /
`SODIUM_CRYPTO_SECRETBOX_NONCEBYTES`, чтобы домен не зависел от Sodium
(AC3).

### Доменные события

- `SecretDomainEvent` — маркер-интерфейс.
- `SecretCreated` (`final readonly`, `implements SecretDomainEvent`): `Token $token`, `DateTimeImmutable $createdAt`.
- Только фиксация на агрегате; `MessageBus`/`EventDispatcher` не используется (AC13).

### Доменные исключения

- `SecretException extends \DomainException` — базовое.
- `InvalidToken`, `InvalidTtl`, `InvalidHint`, `InvalidPlaintext`,
  `InvalidSecretKey`, `InvalidEncryptedPayload`, `TokenGenerationFailed` —
  пустые классы-наследники (0 executable statements, не портят покрытие).

### Порты

```php
// Secret/Domain/Repository/SecretRepository.php
interface SecretRepository
{
    public function save(Secret $secret): void;
    public function findByToken(Token $token): ?Secret;   // чтение агрегата (нужно для интеропа и будущего Reveal)
    public function tokenExists(Token $token): bool;
}

// Secret/Domain/Service/TokenGenerator.php
interface TokenGenerator
{
    public function generate(): Token;
}

// Crypto/Domain/Cryptography.php
interface Cryptography
{
    public function encrypt(Plaintext $plaintext, SecretKey $secret): EncryptedPayload;
    public function decrypt(EncryptedPayload $payload, SecretKey $secret): DecryptionResult;
}

// Crypto/Domain/DecryptionResult.php — маркер
// Crypto/Domain/DecryptedPlaintext.php — final readonly, Plaintext $plaintext
// Crypto/Domain/DecryptionFailed.php — final readonly, static invalidKey(): self
```

Типизированный `DecryptionResult` вместо `?Plaintext` выбран по
`docs/ARCHITECTURE.md` §6: неверный ключ — ожидаемая бизнес-альтернатива, а не
`null` из глубины. Use case `RevealSecret` (003) отобразит его в свой результат.

> Архитектурное следствие: VO `Plaintext`/`SecretKey`/`EncryptedPayload`
> размещены в `Secret/Domain/Model` согласно требованиям, поэтому порт
> `Crypto/Domain/Cryptography` и его адаптер зависят от `Secret_Domain`.
> Зависимость ациклична (`Secret_Application → Crypto_Domain → Secret_Domain`) и
> фиксируется в deptrac и ADR 0002.

### Политика уникальности токена

`UniqueTokenGenerator` (`Secret/Domain/Service`): композиция
`TokenGenerator` + `SecretRepository::tokenExists()`, повторяет генерацию до
`MAX_ATTEMPTS = 5`; при исчерпании бросает `TokenGenerationFailed`. Легаси-цикл
был безграничным; граница добавлена как защита от вечного цикла и не наблюдаема
при нормальной случайности (AC14).

### Application `CreateSecret`

- `CreateSecret` (команда, `final readonly`): `string $plaintext`, `?string $hint`, `string $secretKey` — примитивы на входе сценария.
- `CreateSecretResult` (`final readonly`): `Token $token`.
- `CreateSecretHandler`: зависимости `Cryptography`, `UniqueTokenGenerator`,
  `SecretRepository`, `Psr\Clock\ClockInterface`, `Ttl`.
  Алгоритм: `generate token → encrypt(Plaintext::fromString, SecretKey::fromString) → Secret::create(..., clock->now(), ttl) → repository->save() → CreateSecretResult`.
  Генерации токена, TTL, Sodium и `persist/flush` в контроллере нет (AC9).

### Presentation

- `CreateSecretInput` — форма-DTO, **дословно** повторяющая ограничения и
  сообщения `App\Dto\CreatePasteData` (`text`/`hint`/`secret`; тексты AC6).
- `CreateSecretType` — те же поля и опции, что `CreatePasteType`;
  `data_class = CreateSecretInput`; `csrf_token_id = 'create_paste'`;
  `getBlockPrefix(): string { return 'create_paste'; }` (Q5-A) — поля остаются
  `create_paste[text]` и т.д.
- `CreateSecretController` — `#[Route('/', name: 'app_home', methods: ['GET','POST'])]`:
  принимает запрос, лимитирует (`RateLimiterFactory $createPasteLimiter`,
  сообщение «Слишком много запросов. Попробуйте через минуту.», AC8), валидирует
  форму, вызывает `CreateSecretHandler`, редиректит на `app_paste_created`.
  Рендерит `paste/create.html.twig`; статус 422 для невалидной формы
  обеспечивает штатный `AbstractController::render(..., ['form' => $form])`,
  как в легаси.
- `config/routes.yaml`: добавляется импорт атрибутов
  `../src/Secret/Presentation/Http/` с namespace
  `App\Secret\Presentation\Http`; импорт легаси `../src/Controller/` сохраняется.

### Легаси-совместимость

- `PasteController::create()` удаляется вместе со своими импортами
  (`CreatePasteData`, `CreatePasteType`, `EntityManagerInterface`, `DateInterval`)
  и больше не требует `$pasteTtlDays`.
- `PasteController::created()` и `view()` остаются без изменений; маршрут
  `app_paste_created` и шаблон `paste/created.html.twig` не меняются.
- `CreatePasteType`/`CreatePasteData` остаются в коде до задачи 005 (out of
  scope), хотя production-путь их больше не использует.
- Маршруты, URL, тексты, CSRF, формат ссылок, схема БД и крипто-формат не
  меняются (AC1, AC6, AC11).

## Тест-план

Расположение зеркалит `src` (`docs/CONVENTIONS.md` §7). Все недетерминированные
зависимости — через порты.

- **unit:**
  - `tests/Unit/Secret/Domain/Model/TokenTest.php` — валидный 32-hex; uppercase/неверная длина/неhex/пустой → `InvalidToken`.
  - `tests/Unit/Secret/Domain/Model/TtlTest.php` — `fromDays(7)->expiresAtFrom(base) == base + 7 дней`; `0`/отрицательный → `InvalidTtl`.
  - `tests/Unit/Secret/Domain/Model/HintTest.php` — `null`, `''`, `'   '` → `null`; `' подсказка '` сохраняется как есть; >255 → `InvalidHint` (AC7).
  - `tests/Unit/Secret/Domain/Model/PlaintextTest.php` — валидный; пустой/`>100000` → `InvalidPlaintext`.
  - `tests/Unit/Secret/Domain/Model/SecretKeyTest.php` — валидный; пустой/`>255` → `InvalidSecretKey`.
  - `tests/Unit/Secret/Domain/Model/EncryptedPayloadTest.php` — валидные длины; неверные salt/nonce; пустой ciphertext → `InvalidEncryptedPayload`; сверка `SALT_BYTES`/`NONCE_BYTES` с константами Sodium (AC3).
  - `tests/Unit/Secret/Domain/Model/SecretTest.php` — `create()` даёт `expiresAt = createdAt + Ttl`; зафиксировано ровно одно `SecretCreated`; `pullDomainEvents()` отдаёт его один раз, затем пусто; `isExpiredAt()` на границе; геттеры (AC2, AC13).
  - `tests/Unit/Secret/Domain/Service/UniqueTokenGeneratorTest.php` — свободный токен отдаётся сразу; коллизия → берётся следующий; все коллизии (5) → `TokenGenerationFailed` (AC14).
  - `tests/Unit/Secret/Infrastructure/Token/RandomTokenGeneratorTest.php` — формат `[a-f0-9]{32}`, два вызова различаются (AC14).
  - `tests/Unit/Crypto/Domain/DecryptionResultTest.php` — `DecryptedPlaintext` несёт plaintext; `DecryptionFailed::invalidKey()` — экземпляр `DecryptionResult`.
  - Тест-дубли в `tests/Support/Secret/`: `InMemorySecretRepository`, `FixedTokenGenerator`, `FixedClock` (реализует `Psr\Clock\ClockInterface`), `RecordingCryptography`.
- **application:**
  - `tests/Unit/Secret/Application/CreateSecret/CreateSecretHandlerTest.php` на in-memory портах и `FixedClock`/`FixedTokenGenerator`:
    - happy path: ровно один `save()`, токен в результате, `expiresAt = now + ttl`, на агрегате есть `SecretCreated` (AC2);
    - коллизия токена: `[занятый, свободный]` → результат со свободным, сохранена одна запись (AC14);
    - в `Cryptography` уходят именно plaintext/secret из команды;
    - отсутствие шины: тест не подписывается ни на какие события (AC13).
- **integration:**
  - `tests/Integration/Crypto/Infrastructure/Sodium/SodiumCryptographyTest.php` — round-trip; неверный ключ → `DecryptionFailed`; уникальность salt/nonce; **интероп в обе стороны**: новый `encrypt` → легаси `EncryptionService::decrypt`; легаси `EncryptionService::encrypt` → новый `decrypt` (AC3).
  - `tests/Integration/Secret/Infrastructure/Persistence/Doctrine/DoctrineSecretRepositoryTest.php` (KernelTestCase, схема через `SchemaTool` из легаси-метаданных): `save()` создаёт строку в `paste` с ожидаемыми колонками; `tokenExists()`; `findByToken()` возвращает агрегат с побайтово равным payload; BLOB читается и как `resource`, и как `string` (AC4, AC5).
  - `tests/Integration/Secret/LegacyInteropTest.php` — Secret, сохранённый легаси `Paste`+`EncryptionService`, читается новым `DoctrineSecretRepository` (AC4).
- **functional:**
  - `tests/Functional/PasteFlowTest.php` — **без изменения ожиданий** (AC11); после Ш9 он автоматически становится тестом «новое создание → легаси Reveal» (AC4).
  - `tests/Functional/CreateSecretFlowTest.php`:
    - GET `/` отдаёт форму `create_paste[...]` с CSRF `create_paste`; валидный POST → `302` на `/created/{token}`, токен `[a-f0-9]{32}`, `/created` содержит абсолютный `/p/{token}` (AC1);
    - невалидные text/secret/hint → `422` и точные русские сообщения (AC6);
    - hint `''`/`'   '` → в БД `null`, непустой hint сохраняется без обрезки (AC7);
    - лимитер `create_paste`: 11-я попытка → «Слишком много запросов…», запись не создаётся (AC8);
    - запись, созданная новым путём, читается легаси `PasteRepository` и расшифровывается легаси `EncryptionService` (AC4).
  - `tests/Functional/SecretWiringTest.php` — контейнер отдаёт `Cryptography`,
    `TokenGenerator`, `SecretRepository`, `Psr\Clock\ClockInterface`,
    `CreateSecretHandler`; `CreateSecretType::getBlockPrefix() === 'create_paste'`
    (AC9, Q5-A).

## Пошаговый план реализации

Каждый шаг — отдельный обратимый коммит; после шага `make qa` зелёный.
Порядок подобран так, чтобы условный порог покрытия (`tools/check-coverage.php`)
не срабатывал на «полупустых» каталогах: тесты идут в одном коммите с кодом.

- **Ш0. Baseline.** `make up` (если нужно), `make qa`, `make console doctrine:schema:validate --env=test`, `make coverage` (сейчас порог пропускается). Зафиксировать текущий вывод. Коммита нет.
- **Ш1. deptrac (Q4-A, AC10 seed).** Добавить слои `Secret_*`, `Crypto_*`, `Shared` и целевые правила; легаси-правила не трогать. `make deptrac` → 0. Коммит: `chore: add target module layers to deptrac`.
- **Ш2. Домен VO (AC7, AC12 seed).** Классы `Secret/Domain/Exception/*`, `Model/{Token,Ttl,Hint,Plaintext,SecretKey,EncryptedPayload}` + unit-тесты. В `config/services.yaml` исключить из авто-регистрации `Secret/Domain/{Model,Event,Exception}/` и `Crypto/Domain/`. Коммит: `feat: add Secret value objects and domain exceptions`.
- **Ш3. Агрегат и событие (AC2, AC13).** `Secret`, `SecretDomainEvent`, `SecretCreated` + unit-тест. Коммит: `feat: add Secret aggregate with SecretCreated event`.
- **Ш4. Порты репозитория и токена (AC14).** `SecretRepository`, `TokenGenerator`, `UniqueTokenGenerator` + тест-дубли и unit-тест. Коммит: `feat: add Secret repository and token ports`.
- **Ш5. Крипто-порт (AC3 контракт).** `Crypto/Domain/{Cryptography,DecryptionResult,DecryptedPlaintext,DecryptionFailed}` + unit-тест. Коммит: `feat: add Cryptography domain port`.
- **Ш6. Sodium-адаптер (AC3, AC4).** `Crypto/Infrastructure/Sodium/SodiumCryptography`; DI-алиас `Cryptography`; интеграционный тест байт-совместимости в обе стороны. Коммит: `feat: add Sodium Cryptography adapter`.
- **Ш7. Persistence-адаптер (AC4, AC5, Q1-A).** `SecretRecord`, `SecretRecordMapper`, `DoctrineSecretRepository` поверх `Doctrine\DBAL\Connection`; DI-алиас `SecretRepository`; интеграционные тесты, включая чтение легаси-записи. `doctrine.yaml` не меняется. Коммит: `feat: add DBAL Secret persistence adapter`.
- **Ш8. Use case (AC2, AC12).** `CreateSecret`, `CreateSecretResult`, `CreateSecretHandler`; фабрика `Ttl` в `services.yaml` (`%app.secret_ttl_days%`); application unit-тесты. Коммит: `feat: add CreateSecret use case`.
- **Ш9. Presentation switch (AC1, AC6, AC8, AC9, AC11, Q5-A/Q6-A).** `CreateSecretInput`, `CreateSecretType`, `CreateSecretController`; импорт маршрутов в `config/routes.yaml`; удалить `PasteController::create` и неиспользуемые импорты, убрать binding `$pasteTtlDays`; DI-алиасы `TokenGenerator`/`Cryptography`/`SecretRepository`, bind `Psr\Clock\ClockInterface: '@clock'`. Прогнать `make test` — `PasteFlowTest` без правок ожиданий. Коммит: `refactor: create Secret via use case`.
- **Ш10. HTTP/поведенческие тесты (AC1, AC5, AC7, AC8, AC15).** `CreateSecretFlowTest`, `SecretWiringTest`. Коммит: `test: cover create-secret HTTP contract`.
- **Ш11. Финальная верификация и документы.** `make qa`, `make coverage`, `make console doctrine:schema:validate --env=test`. После одобрения ADR на G2 — перевести ADR 0002 в `accepted` и обновить `docs/QUALITY.md` §2.2 (ruleset) отдельным docs-коммитом. Коммит: `docs: finalize 002 plan artifacts`.

## Миграции БД

**Нет.** Имя таблицы `paste` и колонки не меняются (Q2-A). Новый адаптер пишет
в существующую схему, ORM-маппинг остаётся только легаси
(`doctrine.yaml` без правок), поэтому `doctrine:schema:validate --env=test` и
`SchemaTool` в functional-тестах видят один и тот же маппинг. Миграция и
переименование `paste → secret` — задача 005.

### Маппинг salt/nonce/ciphertext (Q1-A, AC3/AC5)

- `SecretRecord` — `final readonly`, не ORM-сущность. `SecretRecordMapper::toRecord(Secret)` раскладывает VO в `token`/`hint`/`salt`/`nonce`/`ciphertext`/`created_at`/`expires_at`; `fromRow(array<string,mixed>)` собирает агрегат.
- Запись: `Connection::insert('paste', $data, ['salt' => Types::BLOB, 'nonce' => Types::BLOB, 'ciphertext' => Types::BLOB, 'created_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE])` — DBAL `blob` принимает строку и отдаёт `resource` при чтении.
- Чтение: `Connection::fetchAssociative('SELECT … FROM paste WHERE token = :token', ['token' => …])`; BLOB нормализуется как `resource|string` (`stream_get_contents` для resource, проверка типа иначе) — та же логика, что в легаси `Paste::blob()`; даты возвращаются строкой БД и парсятся в `DateTimeImmutable`.
- Второй ORM-маппинг на `paste` **не создаётся**, поэтому дрейф `schema:validate` невозможен. Аварийный путь, если DBAL-подход отклонят на G2, — Q1-B (ACL: маппер раскладывает VO в легаси `App\Entity\Paste`), зависимость `Infrastructure → App\Entity` временная до 005.

## Влияние на стандарты (ADR, если нужно)

**ADR 0002 нужен**: `docs/adr/0002-secret-persistence-and-deptrac.md` (статус
`proposed`). Он фиксирует два неочевидных и долгоживущих решения, влияющих на
стандарты:

1. Реализация Q1-A — DBAL persistence-модель + явный маппер вместо второго
   ORM-маппинга на `paste`; ARCHITECTURE §5 не различает ORM/DBAL, выбор
   закрепляется ADR, чтобы 003/004/005 не переоткрывали вопрос.
2. Итоговый целевой набор слоёв deptrac (Q4-A), включая
   `Crypto_Domain: [Secret_Domain, Shared]` (следствие размещения VO в
   `Secret/Domain/Model`) — уточняет ориентир `docs/QUALITY.md` §2.2.

После одобрения на G2 ADR переводится в `accepted`, и стандарты обновляются:
`docs/QUALITY.md` §2.2 (ruleset) и уточняющая строка в `docs/ARCHITECTURE.md` §5.
До этого правила применяются как согласованные на G1/Q4-A. Отдельный **стоп
G2** на ADR — см. отчёт оркестратору.

Проектируемый фрагмент `deptrac.yaml` (легаси-слои и их правила остаются
дословно как сейчас):

```yaml
deptrac:
  paths: [src]
  layers:
    # ... существующие легаси-слои Kernel/Schedule/Controller/Form/Dto/Entity/
    #     Repository/Service/Command/EventSubscriber/Symfony/Doctrine/Psr/Cron ...

    - { name: Secret_Domain,         collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Domain\\#' }] }
    - { name: Secret_Application,    collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Application\\#' }] }
    - { name: Secret_Infrastructure, collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Infrastructure\\#' }] }
    - { name: Secret_Presentation,   collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Presentation\\#' }] }
    - { name: Crypto_Domain,         collectors: [{ type: classNameRegex, value: '#^App\\Crypto\\Domain\\#' }] }
    - { name: Crypto_Infrastructure, collectors: [{ type: classNameRegex, value: '#^App\\Crypto\\Infrastructure\\#' }] }
    - { name: Shared,                collectors: [{ type: classNameRegex, value: '#^App\\Shared\\#' }] }
  ruleset:
    # ... существующие легаси-правила без изменений ...
    Shared: []
    Secret_Domain: [Shared]
    Crypto_Domain: [Secret_Domain, Shared]
    Secret_Application: [Secret_Domain, Crypto_Domain, Shared]
    Crypto_Infrastructure: [Crypto_Domain, Secret_Domain, Shared]
    Secret_Infrastructure: [Secret_Domain, Secret_Application, Crypto_Domain, Shared, Doctrine]
    Secret_Presentation: [Secret_Application, Secret_Domain, Symfony, Psr]
```

Обоснование рёбер: `Crypto_Domain → Secret_Domain` — порт `Cryptography`
принимает/возвращает `Plaintext`/`SecretKey`/`EncryptedPayload` из
`Secret/Domain/Model` (требования G1). Deptrac по умолчанию запрещает всё, что не
разрешено явно, поэтому `Crypto_Domain` присутствует в ruleset.

### Проектируемые bindings/алиасы (`config/services.yaml`)

```yaml
parameters:
    app.secret_ttl_days: '%env(int:APP_PASTE_TTL_DAYS)%'

services:
    _defaults:
        autowire: true
        autoconfigure: true
        bind:
            $configuredOpsLimit: '%env(int:APP_KDF_OPSLIMIT)%'
            $configuredMemLimit: '%env(int:APP_KDF_MEMLIMIT)%'
            $createPasteLimiter: '@limiter.create_paste'
            $unlockPasteLimiter: '@limiter.unlock_paste'
            Psr\Clock\ClockInterface: '@clock'

    App\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'
            - '../src/Kernel.php'
            - '../src/Secret/Domain/Model/'
            - '../src/Secret/Domain/Event/'
            - '../src/Secret/Domain/Exception/'
            - '../src/Crypto/Domain/'
            - '../src/Secret/Infrastructure/Persistence/Doctrine/SecretRecord.php'

    App\Secret\Domain\Model\Ttl:
        factory: ['App\Secret\Domain\Model\Ttl', 'fromDays']
        arguments: ['%app.secret_ttl_days%']

    App\Secret\Domain\Repository\SecretRepository: '@App\Secret\Infrastructure\Persistence\Doctrine\DoctrineSecretRepository'
    App\Secret\Domain\Service\TokenGenerator: '@App\Secret\Infrastructure\Token\RandomTokenGenerator'
    App\Crypto\Domain\Cryptography: '@App\Crypto\Infrastructure\Sodium\SodiumCryptography'
```

`$pasteTtlDays`/`app.paste_ttl_days` удаляются (после Ш9 их никто не
потребляет); env `APP_PASTE_TTL_DAYS` сохраняется. Если `@clock` окажется
недоступен, fallback — собственный `Shared/Infrastructure/Clock/SystemClock`;
тогда `Shared` получит реальный класс.

## Риски и обратимые шаги

| Риск | Митигация | Обратимость |
|---|---|---|
| Расхождение Q6-A и in-scope №8 (`created`) | Принят Q6-A; явно зафиксировано в плане и вынесено на G2 | Уточнение объёма до реализации |
| Двойной ORM-маппинг `paste` ломает `schema:validate`/`SchemaTool` | Второго ORM-маппинга нет (DBAL); `doctrine.yaml` не тронут; fallback Q1-B (ACL) | Удалить адаптер/маппер, вернуться к ACL |
| Нарушение байт-совместимости крипто | `SodiumCryptography` — копия алгоритма; integration-тест интеропа в обе стороны (AC3) | Откат Ш6 |
| Wire-контракт формы изменится при переименовании классов | `getBlockPrefix() === 'create_paste'`, `csrf_token_id` тот же; functional ловит (AC1, AC6) | Правка `getBlockPrefix` |
| Клиент/открытие сломается при переключении маршрута `/` | Атомарный Ш9: новый контроллер и удаление легаси `create` в одном коммите; `PasteFlowTest` без правок ожиданий (AC11) | `git revert` Ш9 |
| PHPStan L10 на `array<string,mixed>`/BLOB/Doctrine | Сужение типов сразу в маппере; точечные `@phpstan-ignore` только с причиной и датой (формат 001) | Локальные аннотации |
| Порог ≥90% активируется сразу после Ш2 | Unit-тесты идут в одном коммите с кодом; `make coverage` в Ш11 | Добавить тесты/упростить |
| `Exception`/интерфейсы портят покрытие | Исключения — пустые классы, интерфейсы без statements | — |
| Расползание в Reveal/Purge/rate limiting | Явный out of scope; `created` остаётся легаси; лимитер только вызывается в Presentation | Ревью AC9/AC11 |
| `Crypto_Domain → Secret_Domain` сочтён неверным направлением | Зафиксирован ADR 0002; альтернатива (перенос VO в Crypto) — отдельное решение/ADR | ADR |
| Утечка plaintext/ключей/salt/nonce в логи | Новый код не логирует секреты; в тестах проверяются только нейтральные сообщения | — |

## Definition of Done для задачи

- [ ] Решения Q1–Q6 реализованы как A; конфликт Q6-A и in-scope №8 уточнён на G2.
- [ ] Новый код — в `src/Secret`, `src/Crypto`; `src/Shared` зарезервирован, классов в 002 нет; Domain не импортирует Symfony/Doctrine/Sodium (AC10).
- [ ] `SecretCreated` фиксируется на агрегате и не публикуется (AC13).
- [ ] Токен `[a-f0-9]{32}`, уникальность через проверку репозитория (AC14).
- [ ] `make qa` зелёный: `cs-check`, `stan` (L10, 0 ошибок, без baseline), `deptrac` без нарушений, `test` (AC15).
- [ ] `make coverage`: покрытие `src/Secret/Domain`, `src/Secret/Application` (и `src/Crypto/Domain`) ≥ 90% (AC12).
- [ ] `doctrine:schema:validate --env=test` зелёный; миграций нет; имя таблицы `paste` и колонки не изменены (AC5).
- [ ] `PasteFlowTest` и остальные существующие тесты проходят без смены ожиданий; создание→открытие идёт через новый путь + легаси Reveal (AC11, AC4).
- [ ] HTTP-контракт (`/`, `create_paste[...]`, CSRF `create_paste`, 302, `/created/{token}`, `/p/{token}`, 422 и тексты, rate limiting) неизменен (AC1, AC6, AC8).
- [ ] ADR 0002 создан, после одобрения — `accepted`, `docs/QUALITY.md` §2.2 обновлён (AC10, стандарты).
- [ ] `docs/UBIQUITOUS_LANGUAGE.md` не изменён; новые термины не вводились.
- [ ] Артефакты — в `docs/task/002-extract-create-secret/`.
