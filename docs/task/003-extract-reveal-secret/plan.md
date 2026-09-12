# План: 003-extract-reveal-secret

> Статус: **G2 пройден.** Решения G1 (Q1–Q7, все вариант A) обязательны; query
> `LoadRevealSecret` утверждён на G2. Объём — только извлечение сценария выдачи
> `RevealSecret` из легаси; внешнее поведение (`/p/{token}`, тексты, коды,
> `no-store`, rate limiting) неизменно. Burn-after-reading, Purge, Access и
> удаление легаси — соответственно 004/006/005 и вне объёма.

## Связь с требованиями

Задача повторяет эталон 002 (`docs/task/002-extract-create-secret/plan.md`,
ADR 0002) для сценария выдачи: оркестрация уходит в Application, контроллер
становится тонким, ожидаемые бизнес-альтернативы типизированы, домен не знает
фреймворка (`docs/ARCHITECTURE.md` §6, §9.3). Чтение идёт через уже принятый
`SecretRepository`/`SecretRecordMapper` (DBAL, таблица `paste`), порт
`Cryptography` и `Psr\Clock\ClockInterface` — новых стандартов не вводится.

Решения G1 разворачиваются в плане так:

| G1 | Как реализуется в плане | Шаги |
|---|---|---|
| Q1-A — burn-after-reading вне 003 | Запись не удаляется и не мутируется; полей `one_time`/`viewed` нет; `SecretRevealed` не вводится | весь объём, Ш2–Ш4 |
| Q2-A — `Revealed \| InvalidKey \| NotFound`; «нет/истекло» → единый 404; срок через `Secret::isExpiredAt(Clock->now())` | Результат-маркер `RevealSecretResult` + варианты; `SecretRepository::findByToken` без SQL-фильтра срока | Ш2 |
| Q3-A — `decrypt` тотальный, `''` — успех | `DecryptedPlaintext` несёт сырую `string` (не `Plaintext`); regression-тест интеропа с пустым plaintext | Ш1 |
| Q4-A — классы Secret, wire-контракт `unlock_paste` сохранён; маршрут/URL/имя прежние; шаблоны `paste/*.twig` не трогаем | `RevealSecretType::getBlockPrefix() === 'unlock_paste'`, `csrf_token_id: unlock_paste`; `app_paste_view` `/p/{token}` переносится без смены контракта | Ш3 |
| Q5-A — чтение только через новый маппер, легаси `Paste` не трогаем | `DoctrineSecretRepository::findByToken` + `SecretRecordMapper::fromRow` (уже есть в 002); `App\Entity\Paste` в Reveal не участвует | Ш2, Ш3, Ш4 |
| Q6-A — лимитер `unlock_paste` остаётся в Presentation; тесты изолируют окно | `RateLimiterFactory $unlockPasteLimiter` в контроллере, ключ `(ip ?? 'unknown').':'.$token`; `cache.app->clear()` в `setUp()` reveal-тестов и `PasteFlowTest` | Ш3, Ш4 |
| Q7-A — `SecretRevealed` не вводится | Домен при чтении только `reconstitute()`-ится, событие не фиксируется | Ш2 |

Соответствие критериям приёмки:

| AC | Где закрывается |
|---|---|
| AC1 | Ш3 (контроллер/форма/маршрут), Ш4 (`PasteFlowTest` + `RevealSecretFlowTest`) |
| AC2 | Ш2 (use case), Ш3 (`no-store`), Ш4 (повторный Reveal, неизменность записи) |
| AC3 | Ш2 (`InvalidKey`), Ш3 (422/поле `secret`), Ш4 |
| AC4 | Ш2 (`NotFound` для «нет»/«истекло»), Ш3 (единый 404 и текст), Ш4 (нейтральность) |
| AC5 | Ш3 (лимитер в Presentation), Ш4 (11-я отправка; невалидные тоже расходуют лимит) |
| AC6 | Ш3 (ревью контроллера: только форма + use case) |
| AC7 | Ш2 + `tests/Unit/Secret/Application/RevealSecret/*` |
| AC8 | Ш2 (`isExpiredAt`), Ш4 (граница `expiresAt === now`) |
| AC9 | Ш2/Ш4 (чтение через `SecretRecordMapper`; интероп `LegacyInteropTest` + functional) |
| AC10 | Ш3 + `SecretWiringTest` (`getBlockPrefix`, `csrf_token_id`, поля) |
| AC11 | Ш3 (удаляется только `view`; `created` не тронут), существующий `PasteFlowTest` |
| AC12 | Ш1–Ш4 (без логирования секретов; `no-store`; CSRF; нейтральные тексты) |
| AC13 | `make qa`/`make deptrac`/`make coverage`/`schema:validate` в Ш5 |
| AC14 | Ш1 (пустой plaintext), Ш3/Ш4 (без `App\Entity\Paste`; изоляция `cache.app`) |
| AC15 | существующие тесты проходят без смены ожиданий (кроме изоляции лимитера) |

### Проектное уточнение: загрузка формы — отдельный query рядом с командой

Требование AC6 запрещает контроллеру выборку из репозитория и проверку срока, а
AC4 требует нейтральный `404` уже на `GET /p/{token}`. Legacy-контроллер делал
`findActiveByToken` **до** rate limiting и валидации на всех методах. Значит,
Application обязан отдать Presentation две вещи:

1. «Запись есть и активна; вот её публичная подсказка» либо `NotFound` — для
   гейта `404` и рендера формы (в т.ч. на `GET` и при неверном ключе);
2. собственно попытку расшифровки — команда `RevealSecret`.

Поэтому в Application рядом с командой `RevealSecret` (ровно три исхода
`Revealed | InvalidKey | NotFound`, как утверждено Q2-A) появляется
read-only запрос `LoadRevealSecret` (`RevealSecretView | NotFound`). Это не
меняет утверждённый набор исходов Reveal и не является изменением стандартов;
решение выносится на подтверждение G2 (см. «Риски»).

## Затрагиваемые модули и слои

- **Новые классы** — только в существующих слоях:
  `App\Secret\Application\RevealSecret` и
  `App\Secret\Presentation\Http` (Q4 задачи 002). Новых слоёв deptrac нет.
- **Изменяемые классы**: `App\Crypto\Domain\DecryptedPlaintext`,
  `App\Crypto\Infrastructure\Sodium\SodiumCryptography`,
  `App\Controller\PasteController` (удаляется только `view` и его импорты).
- **Не трогаем**: `App\Entity\Paste`, `App\Repository\PasteRepository`,
  `App\Form\UnlockPasteType`, `App\Dto\UnlockPasteData`,
  `App\Service\EncryptionService`, `PasteController::created`,
  `templates/paste/*.twig`, `config/routes.yaml`, `config/services.yaml`
  (`$unlockPasteLimiter` и `Psr\Clock\ClockInterface` уже забиндены),
  `config/packages/rate_limiter.yaml`, `migrations/`, `deptrac.yaml`.
- **Тесты**: `tests/Unit/Secret/Application/RevealSecret/**`,
  `tests/Functional/RevealSecretFlowTest.php`,
  `tests/Support/Secret/RecordingCryptography.php` (расширение),
  правки 002-тестов из-за Q3-A, техническая изоляция `cache.app` в
  `PasteFlowTest`.

### Проектируемая структура (изменения относительно 002)

```text
src/
├── Secret/
│   ├── Application/
│   │   ├── CreateSecret/            # без изменений
│   │   └── RevealSecret/
│   │       ├── RevealSecret.php          # команда (string token, string secretKey)
│   │       ├── RevealSecretHandler.php
│   │       ├── RevealSecretResult.php    # маркер
│   │       ├── Revealed.php
│   │       ├── InvalidKey.php
│   │       ├── NotFound.php
│   │       ├── LoadRevealSecret.php      # query для формы
│   │       ├── LoadRevealSecretHandler.php
│   │       └── RevealSecretView.php      # ?string hint для шаблона
│   └── Presentation/Http/
│       ├── CreateSecretController.php    # без изменений
│       ├── RevealSecretController.php    # новый, app_paste_view
│       ├── Form/RevealSecretType.php
│       └── Input/RevealSecretInput.php
├── Crypto/
│   ├── Domain/DecryptedPlaintext.php     # string вместо Plaintext (Q3-A)
│   └── Infrastructure/Sodium/SodiumCryptography.php  # тотальный decrypt
└── Controller/PasteController.php        # остаётся только created()
```

## Изменение модели/контрактов (агрегаты, VO, порты)

### Application `RevealSecret` (Q2-A, Q7-A)

Команда и query — `final readonly` с примитивами на границе (как
`CreateSecret`):

```php
final readonly class RevealSecret
{
    public function __construct(public string $token, public string $secretKey) {}
}

final readonly class LoadRevealSecret
{
    public function __construct(public string $token) {}
}
```

Типизированный результат — маркер + варианты (паттерн `DecryptionResult` из
002), все в `Secret/Application/RevealSecret`:

```php
interface RevealSecretResult {}

final readonly class Revealed implements RevealSecretResult
{
    private function __construct(public string $plaintext) {}
    public static function fromPlaintext(string $plaintext): self { return new self($plaintext); }
}

final readonly class InvalidKey implements RevealSecretResult
{
    private function __construct() {}
    public static function invalidKey(): self { return new self(); }
}

final readonly class NotFound implements RevealSecretResult
{
    private function __construct() {}
    public static function notFound(): self { return new self(); }
}
```

Представление формы (результат query, не исход Reveal):

```php
final readonly class RevealSecretView
{
    public function __construct(public ?string $hint) {}
}
```

`RevealSecretHandler` зависит только от портов
(`SecretRepository`, `Cryptography`, `ClockInterface`):

```php
public function __invoke(RevealSecret $command): RevealSecretResult
{
    $secret = $this->secretRepository->findByToken(new Token($command->token));
    if (null === $secret || $secret->isExpiredAt($this->clock->now())) {
        return NotFound::notFound();                 // «нет записи» и «истекло» неразличимы (AC4/AC8)
    }

    $decryption = $this->cryptography->decrypt(
        $secret->payload(),
        SecretKey::fromString($command->secretKey),
    );

    if ($decryption instanceof DecryptedPlaintext) {
        return Revealed::fromPlaintext($decryption->plaintext);   // empty plaintext — валидный успех (Q3-A)
    }

    return InvalidKey::invalidKey();                 // DecryptionResult замкнут на два варианта
}
```

`LoadRevealSecretHandler` (`RevealSecretView|NotFound`) повторяет тот же
гейт «найти + `isExpiredAt`» и не расшифровывает:

```php
$secret = $this->secretRepository->findByToken(new Token($query->token));
if (null === $secret || $secret->isExpiredAt($this->clock->now())) {
    return NotFound::notFound();
}
return new RevealSecretView($secret->hint()?->value);
```

Инварианты:
- неверный ключ и отсутствие записи **не бросают** исключений и не возвращают
  `null` (AC7);
- срок проверяется в Application (`Secret::isExpiredAt`), репозиторий порт не
  расширяется и SQL-фильтр срока не дублируется (AC8);
- `Secret` при чтении только `reconstitute()`-ится, `SecretRevealed` не
  фиксируется (Q7-A);
- невалидный формат токена до Application не доходит: маршрут
  `requirements: ['token' => '[a-f0-9]{32}']` отдаёт `404` до контроллера
  (AC4), поэтому `new Token(...)` в обработчике не ловится — нарушение
  инварианта остаётся доменным исключением (§6).

### Q3-A: тотальный `Cryptography::decrypt` для пустого plaintext

Сейчас `DecryptedPlaintext` оборачивает `Plaintext`, а `Plaintext::fromString('')`
бросает `InvalidPlaintext`; легаси `EncryptionService::decrypt('')` вернул бы `''`.
`Plaintext` ослаблять нельзя — он защищает инвариант создания (`NotBlank`).
Решение: успешный результат расшифровки несёт **сырую строку**, а не VO с
инвариантом «непустой»:

```php
final readonly class DecryptedPlaintext implements DecryptionResult
{
    public function __construct(public string $plaintext) {}   // '' допустим
}
```

`SodiumCryptography::decrypt()` больше не вызывает `Plaintext::fromString()` и
возвращает `new DecryptedPlaintext($plaintext)` (в т.ч. для `''`). `encrypt()`
по-прежнему принимает `Plaintext` — создание пустого текста запрещено, как и
раньше. `Crypto_Domain → Secret_Domain` сохраняется (порт `encrypt`), ADR 0002
не пересматривается.

Правки 002 носят механический характер (поведение не меняется, production
002 `decrypt` не вызывает):
`tests/Unit/Crypto/Domain/DecryptionResultTest.php` (`string` вместо `Plaintext`),
`tests/Integration/Crypto/Infrastructure/Sodium/SodiumCryptographyTest.php`
(`$result->plaintext` вместо `->plaintext->value`),
`tests/Integration/Secret/LegacyInteropTest.php` (то же).

### Чтение через новый persistence (Q5-A)

`SecretRepository::findByToken(Token): ?Secret` и
`SecretRecordMapper::fromRow(array): Secret` уже реализованы в 002 и
обрабатывают BLOB как `resource|string` (свежее чтение на запрос, ресурс
читается один раз). Порт и маппер **не меняются**. `App\Entity\Paste` и его
неидемпотентный `getEncryptedPayload()` в Reveal не используются (Q5-A/Н10).
Миграций и колонок не добавляется.

### Presentation (Q4-A)

- `RevealSecretInput` — дословная копия ограничений и сообщений
  `UnlockPasteData` (`#NotBlank('Введите секретный ключ')`,
  `#Length(max: 255, maxMessage: 'Ключ не должен превышать 255 символов')`,
  `public string $secret = ''`).
- `RevealSecretType` — поле `secret` (`PasswordType`, label «Секретный ключ»,
  `empty_data: ''`, `attr.maxlength: 255`, `attr.autocomplete: current-password`),
  `data_class = RevealSecretInput`, `csrf_token_id = 'unlock_paste'` и
  **обязательный** `getBlockPrefix(): string { return 'unlock_paste'; }` —
  иначе поля стали бы `reveal_secret[...]` (Q4-A/AC10).
- `RevealSecretController` — тот же маршрут, что легаси:
  `#[Route('/p/{token}', name: 'app_paste_view', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET','POST'])]`.
  Алгоритм (тонкий, AC6):

```php
$view = $loadRevealSecret(new LoadRevealSecret($token));
if ($view instanceof NotFound) {
    throw $this->createNotFoundException('Запись не найдена или срок её хранения истёк');
}

$input = new RevealSecretInput();
$form = $this->createForm(RevealSecretType::class, $input);
$form->handleRequest($request);

if ($form->isSubmitted()) {
    $key = ($request->getClientIp() ?? 'unknown').':'.$token;
    if (!$unlockPasteLimiter->create($key)->consume()->isAccepted()) {
        $form->addError(new FormError('Слишком много попыток. Попробуйте через минуту.'));
    } elseif ($form->isValid()) {
        $result = $revealSecret(new RevealSecret($token, $input->secret));
        if ($result instanceof Revealed) {
            $response = $this->render('paste/revealed.html.twig', ['plaintext' => $result->plaintext]);
            $response->headers->set('Cache-Control', 'no-store');
            return $response;
        }
        if ($result instanceof NotFound) {
            throw $this->createNotFoundException('Запись не найдена или срок её хранения истёк');
        }
        $form->get('secret')->addError(new FormError('Неверный ключ'));
    }
}

return $this->render('paste/view.html.twig', ['paste' => $view, 'form' => $form]);
```

  Порядок «404 → rate limit → валидация → расшифровка» воспроизводит легаси
  (невалидные отправки тоже расходуют лимит, AC5). `RevealSecretView`
  подставляется в шаблон как `paste`; `{{ paste.hint }}` даёт ту же строку,
  что `Paste::getHint()` (шаблоны не меняются). Ключ лимитера, тексты,
  `no-store`, `422`/`404` — прежние. В контроллере нет выборки из репозитория,
  проверки `expiresAt`, Sodium и бизнес-ветвлений расшифровки (AC6).
- `config/routes.yaml` не меняется: импорт `../src/Secret/Presentation/Http/`
  уже присутствует; маршрут переезжает туда вместе с контроллером.
- Тип параметра `RateLimiterFactory` (конкретный класс) сохраняется как в 002 и
  в существующем binding `$unlockPasteLimiter: '@limiter.unlock_paste'`.

### Легаси-совместимость

- Из `PasteController` удаляется только `view()` и ставшие ненужными импорты
  (`UnlockPasteData`, `UnlockPasteType`, `EncryptionService`, `DateTimeImmutable`,
  `FormError`, `Request`, `RateLimiterFactory`); `created()` и его импорты
  (`Paste`, `PasteRepository`, `Response`, `Route`, `UrlGeneratorInterface`)
  не меняются (AC11).
- Легаси-классы `UnlockPasteType`, `UnlockPasteData`, `EncryptionService`,
  `PasteRepository::findActiveByToken` остаются в коде до 005 (scope #7).
- Маршруты/имена/URL/requirement, тексты, wire-контракт формы, CSRF, схема БД и
  крипто-формат не меняются.

## Тест-план

Расположение зеркалит `src` (`docs/CONVENTIONS.md` §7). Недетерминизм — только
через порты.

- **unit (домен):**
  - `tests/Unit/Secret/Domain/Model/SecretTest.php` — уже покрывает
    `isExpiredAt` на границе (`expiresAt-1s` → активен, `expiresAt` и
    `expiresAt+1s` → истёк). Отдельный новый класс не нужен; граничный кейс
    продублирован в application-тесте для AC8.
- **application:**
  - `tests/Unit/Secret/Application/RevealSecret/RevealSecretHandlerTest.php`
    (in-memory репозиторий, `FixedClock`, `RecordingCryptography`):
    - успех: `Cryptography` вернул `DecryptedPlaintext('текст')` → `Revealed`
      с точным plaintext; в `decrypt` ушли payload агрегата и
      `SecretKey::fromString(команда)` (AC2, AC7);
    - `''` от `Cryptography` → `Revealed('')` (Q3-A, AC14);
    - `DecryptionFailed::invalidKey()` → `InvalidKey`, исключений нет (AC3/AC7);
    - нет записи → `NotFound`, `Cryptography::decrypt` не вызван (AC4);
    - `expiresAt < now`, `expiresAt === now` → `NotFound`, decrypt не вызван
      (AC4/AC8);
    - `expiresAt > now` → попытка расшифровки (AC8).
  - `tests/Unit/Secret/Application/RevealSecret/LoadRevealSecretHandlerTest.php`:
    - активный Secret с подсказкой → `RevealSecretView` с этой строкой;
    - активный Secret без подсказки → `RevealSecretView(null)`;
    - нет/истёк → `NotFound`.
  - `tests/Support/Secret/RecordingCryptography.php` — добавить
    `public array $decryptions` и необязательный `?DecryptionResult` в
    конструктор (по умолчанию `DecryptionFailed::invalidKey()`), чтобы 002-тест
    `CreateSecretHandlerTest` остался без изменений.
- **integration:**
  - `tests/Integration/Crypto/Infrastructure/Sodium/SodiumCryptographyTest.php`:
    - обновить обращения к `->plaintext` (Q3-A);
    - regression: легаси `EncryptionService::encrypt('', key)` → новый
      `decrypt` → `DecryptedPlaintext` со значением `''` (Q3-A/AC14).
  - `tests/Integration/Secret/LegacyInteropTest.php`: обновить
    `->plaintext->value` → `->plaintext`; существующие кейсы «легаси→новый» и
    «новый→легаси» остаются (AC9).
- **functional:**
  - `tests/Functional/RevealSecretFlowTest.php` (WebTestCase, `setUp`:
    `cache.app->clear()`, `SchemaTool::dropSchema/createSchema`, отдельный
    `REMOTE_ADDR`):
    - `GET /p/{token}` активного Secret → `200`, форма `paste/view.html.twig`,
      `unlock_paste[secret]`, `unlock_paste[_token]`, кнопка «Расшифровать»,
      подсказка видна; отсутствие подсказки — тега `.hint` нет (AC1/AC10);
    - валидный `POST` → `200`, `#plaintext` содержит точный текст,
      `Cache-Control` содержит `no-store`; запись после Reveal не удалена,
      `expires_at` прежний, повторный Reveal тем же ключом снова успешен
      (AC2);
    - неверный ключ → `422`, поле `secret` содержит «Неверный ключ», plaintext
      отсутствует (AC3);
    - неизвестный токен и истёкший Secret (`App\Entity\Paste` с
      `expiresAt` в прошлом) → `404`; через `catchExceptions(false)` оба
      бросают `NotFoundHttpException` с одним текстом «Запись не найдена или
      срок её хранения истёк» (нейтральность, AC4); токен не по формату даёт
      `404` на уровне маршрута (AC4);
    - rate limiting: 10 неверных `POST` → `422` с «Неверный ключ»; 11-й (даже с
      верным ключом) → `422` с root-ошибкой «Слишком много попыток. Попробуйте
      через минуту.» и без plaintext — доказывает, что лимитер проверяется до
      расшифровки; отдельный кейс: 10 пустых отправок расходуют лимит (AC5);
    - валидация: пустой и >255 ключ → `422` с точными русскими сообщениями
      (AC10);
    - интероп: Secret, записанный легаси `Paste` + `EncryptionService`, успешно
      открывается новым `POST /p/{token}` (AC9).
  - `tests/Functional/SecretWiringTest.php` — дополнить: контейнер отдаёт
    `RevealSecretHandler`, `LoadRevealSecretHandler`;
    `new RevealSecretType()->getBlockPrefix() === 'unlock_paste'`,
    `csrf_token_id === 'unlock_paste'`; роутер генерирует
    `app_paste_view` ровно как `/p/{token}` (AC1/AC10).
  - `tests/Functional/PasteFlowTest.php` — **ожидания не меняются** (AC15); в
    `setUp()` добавляется очистка `cache.app` (техническая изоляция лимитера,
    Q6-A/AC14/Н9). После Ш3 тест автоматически исполняет Reveal новым путём.

## Пошаговый план реализации

Каждый шаг — отдельный обратимый коммит; после шага `make qa` зелёный. Тесты
идут в одном коммите с кодом, чтобы порог покрытия `tools/check-coverage.php`
(≥90% для `src/*/{Domain,Application}`) не срабатывал на «полупустых»
каталогах.

- **Ш0. Baseline.** `make up` (если нужно), `make qa`, `make coverage`,
  `make console doctrine:schema:validate --env=test`; зафиксировать вывод на
  чистом кеше. Коммита нет.
- **Ш1. Тотальный decrypt (Q3-A, AC14).** `DecryptedPlaintext` → `string`;
  `SodiumCryptography::decrypt` без `Plaintext::fromString`; механические
  правки трёх 002-тестов; regression-тест пустого plaintext через легаси
  `encrypt('')`. Коммит: `refactor: make Cryptography::decrypt total for empty plaintext`.
- **Ш2. Use case (Q2-A/Q5-A/Q7-A, AC2/AC3/AC4/AC7/AC8).** Классы
  `Application/RevealSecret/*` (команда, результат-маркер и три варианта,
  query `LoadRevealSecret` + `RevealSecretView`, оба обработчика); расширение
  `RecordingCryptography`; application unit-тесты. Коммит:
  `feat: add RevealSecret use case`.
- **Ш3. Presentation switch (Q4-A/Q6-A, AC1/AC5/AC6/AC10/AC11).**
  `RevealSecretInput`, `RevealSecretType`, `RevealSecretController`; удаление
  `PasteController::view` и неиспользуемых импортов. `config/routes.yaml` и
  `services.yaml` не меняются. Прогнать `make test` — `PasteFlowTest`
  проходит без смены ожиданий. Коммит: `refactor: reveal Secret via use case`.
- **Ш4. HTTP/поведенческие тесты (AC1–AC15).** `RevealSecretFlowTest`,
  дополнение `SecretWiringTest`, изоляция `cache.app` в `PasteFlowTest`.
  Коммит: `test: cover reveal-secret HTTP contract`.
- **Ш5. Финальная верификация и документы.** `make qa` (cs-check, PHPStan L10
  без baseline, deptrac 0, PHPUnit), `make coverage`, `doctrine:schema:validate
  --env=test`; обновить `docs/task/ROADMAP.md` (статус/журнал 003). Коммит:
  `docs: finalize 003 reveal-secret artifacts`.

## Миграции БД

**Нет.** Таблица `paste` и колонки не меняются; Reveal читает существующие
строки тем же `DoctrineSecretRepository`/`SecretRecordMapper`, что и 002
(Q2-A задачи 002/ADR 0002). Новых ORM-маппингов нет, поэтому
`doctrine:schema:validate --env=test` и `SchemaTool::createSchema` видят тот же
маппинг. Переименование `paste → secret` — задача 005.

## Влияние на стандарты (ADR, если нужно)

**ADR 0003 не нужен.** Задача:
- следует уже принятому ADR 0002 (DBAL persistence-модель + маппер, таблица
  `paste`, целевой deptrac-набор) — не переоткрывает ни одного его решения;
- не добавляет и не меняет слои/правила `deptrac.yaml` (используются
  существующие `Secret_Application`/`Secret_Presentation`, которым уже
  разрешены `Crypto_Domain`, `Secret_Domain`, `Symfony`, `Psr`);
- не меняет схему БД, крипто-формат, маршруты/URL, wire-контракт и стандарты
  качества;
- не вводит новых доменных терминов — `docs/UBIQUITOUS_LANGUAGE.md` не
  меняется;
- не вводит доменных событий (Q7-A).

Смена типа успеха `DecryptedPlaintext` (`Plaintext` → `string`) — внутренний
контракт модуля `Crypto`, не архитектурный стандарт. Единственное решение,
требующее явного подтверждения, — отдельный query `LoadRevealSecret` рядом с
командой (см. «Связь с требованиями» и «Риски»); оно фиксируется в этом плане
на гейте G2 и не требует ADR.

## Риски и обратимые шаги

| Риск | Митигация | Обратимость |
|---|---|---|
| `LoadRevealSecret` не назван в requirements | Явно обоснован необходимостью AC4+AC6; вынесен на G2; альтернатива — nullable-ключ и 4-й вариант результата, что отклонено как расширение утверждённого набора | Уточнение на G2 до реализации; иначе `git revert` Ш2–Ш3 |
| Смена типа `DecryptedPlaintext` затрагивает 002-тесты | Правки механические; production 002 `decrypt` не вызывает; поведение create не меняется | `git revert` Ш1 |
| Двойное чтение Secret на POST (query + command) | Индексированный `findByToken`; `NotFound` команды закрывает гонку «истекло между запросами» | Локально |
| Нейтральность 404 нарушена (разные тексты «нет»/«истекло») | Единый `NotFound` → один `createNotFoundException` с одним текстом; functional проверяет сообщение через `catchExceptions(false)` (AC4) | Правка ветки |
| Потеря `Cache-Control: no-store` | Явная установка заголовка на успехе + functional-ассерт (AC2) | Правка контроллера |
| Изменение wire-контракта при переименовании формы | Обязательный `getBlockPrefix() === 'unlock_paste'`, `csrf_token_id: unlock_paste`; `SecretWiringTest` + functional (AC10) | Правка `getBlockPrefix` |
| Изменение ключа/текста rate limiter | Ключ `(ip ?? 'unknown').':'.$token`, текст «Слишком много попыток…», лимитер в Presentation (AC5) | `git revert` Ш3 |
| Пустой plaintext → исключение/500 | Q3-A: тотальный `decrypt`, `''` — успех, regression-тест (AC14) | `git revert` Ш1 |
| Неидемпотентность легаси `Paste::getEncryptedPayload()` | Reveal читает через новый маппер; `App\Entity\Paste` не используется (Q5-A/Н10) | Ревью AC9 |
| Флейки тестов из-за общего окна лимитера | `cache.app->clear()` в `setUp()` reveal-тестов и `PasteFlowTest`; уникальный токен в ключе (Q6-A/Н9) | — |
| Конфликт имени маршрута `app_paste_view` при переезде | Удаление `view` и добавление нового контроллера — в одном коммите Ш3 | `git revert` Ш3 |
| Проверка срока `<=` vs `<` | Домен `isExpiredAt` уже использует `<=` (тест границы в Ш2/Ш4), что совпадает с SQL `expiresAt > :now` (AC8) | — |
| Покрытие `Application/RevealSecret` ниже 90% | unit-тесты всех ветвей идут в том же коммите, что и код (Ш2) | Добавить тесты |
| Невалидный токен в use case → `InvalidToken` | Маршрут отсекает невалидный формат до контроллера (AC4); нарушение инварианта не превращается в бизнес-результат (§6) | — |
| Расползание в 004/005/006/схему | Явный out of scope; `created`, Purge, Access, удаление легаси не трогаются | Ревью AC6/AC11 |

## Definition of Done для задачи

- [ ] Решения Q1–Q7 реализованы как A: burn-after-reading вне объёма, исходы
      `Revealed | InvalidKey | NotFound`, тотальный `decrypt`, сохранённый
      wire-контракт, чтение через новый маппер, лимитер в Presentation,
      `SecretRevealed` не вводится.
- [ ] Use case и типизированный результат — в `src/Secret/Application/RevealSecret`;
      Presentation выдачи — в `src/Secret/Presentation/Http`; Domain без
      фреймворка; наблюдаемое поведение не изменилось.
- [ ] Истечение вычисляется `Secret::isExpiredAt(Clock->now())`; репозиторий порт
      не расширен; `App\Entity\Paste` в Reveal не участвует.
- [ ] `make qa` зелёный: cs-check, PHPStan L10 — 0 ошибок без baseline, deptrac —
      0 нарушений (без новых правил), PHPUnit.
- [ ] `make coverage`: `src/Secret/Domain`, `src/Secret/Application`,
      `src/Crypto/Domain` ≥ 90%.
- [ ] `doctrine:schema:validate --env=test` зелёный; миграций нет; схема и
      крипто-формат не менялись.
- [ ] Reveal покрыт: успех + `no-store` + plaintext/повторный Reveal, неверный
      ключ `422`, неизвестное/истёкшее `404` с единым нейтральным текстом,
      rate limiting (в т.ч. расход невалидными отправками), валидация,
      пустой plaintext, интероп с записями 002/легаси (AC9/AC14).
- [ ] `PasteController::view` удалён; `created` и маршрут `app_paste_created` не
      затронуты; легаси-классы Reveal сохранены до 005.
- [ ] `docs/UBIQUITOUS_LANGUAGE.md` не изменён; ADR не требуется; `ROADMAP.md`
      актуализирован.
- [ ] Артефакты задачи — в `docs/task/003-extract-reveal-secret/`.
