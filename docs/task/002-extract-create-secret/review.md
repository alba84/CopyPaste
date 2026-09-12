# Ревью: 002-extract-create-secret

## Вердикт: approved

Блокирующих замечаний нет. 11 неблокирующих замечаний/рекомендаций (см. ниже).

> **Ограничение верификации.** В песочнице ревьюера терминал IDE и запуск
> PHPUnit недоступны, `vendor/` на месте, но `make qa`, `make coverage` и
> `doctrine:schema:validate --env=test` фактически не прогонялись. Каталоги
> `var/coverage` и `var/phpstan` отсутствуют. Локальный
> `.phpunit.result.cache` содержит незакрытые defect-записи (`PasteFlowTest` —
> 4, `DoctrineSecretRepositoryTest` — 2) и при этом ссылается на удалённый
> `App\Tests\Unit\Smoke\Domain\FooTest`, то есть является частично устаревшим
> (прогон подмножества/промежуточное состояние), а не достоверным свидетельством
> текущего падения. Поэтому AC5, AC10, AC11, AC12 и AC15 проверены **статически**
> (анализ исходников, конфигураций и сгенерированного контейнера
> `var/cache/test/ContainerDyFAAYx/getCreateSecretHandlerService.php`), а не
> исполнением. Утверждение ниже — при условии, что владелец гейта G3 выполнит
> полный чистый прогон `make qa` + `make coverage` +
> `doctrine:schema:validate --env=test` (см. Н1).

## Соответствие requirements.md

Diff проверен построчно: новые `src/Secret/**`, `src/Crypto/**`,
`tests/**/{Unit,Integration,Functional,Support}`, изменения
`config/routes.yaml`, `config/services.yaml`, `deptrac.yaml`,
`src/Controller/PasteController.php`, `docs/ARCHITECTURE.md`, `docs/QUALITY.md`.

| AC | Статус | Обоснование |
|---|---|---|
| AC1 | выполнено (инспекция) | `CreateSecretController.php:20` — `app_home`, `methods: ['GET','POST']`; `PasteController.php:23` сохраняет `app_paste_created` (`/created/{token}`, regex `[a-f0-9]{32}`) и `app_paste_view` (`/p/{token}`). `CreateSecretType::getBlockPrefix()` = `create_paste` (Form:31-34), `csrf_token_id = create_paste` (Form:28), поля `text/hint/secret` — контракт совпадает с легаси `CreatePasteType`. Functional-проверки: `CreateSecretFlowTest.php:55-91`. |
| AC2 | выполнено | `CreateSecretHandler.php:28-45`: токен → `Cryptography::encrypt` → `Secret::create(...)` → `SecretRepository::save` один раз → `CreateSecretResult`. Уникальность — `UniqueTokenGenerator` (проверка `tokenExists`), unit: `CreateSecretHandlerTest.php:57-68`. |
| AC3 | выполнено (инспекция) | `SodiumCryptography.php:23-65` побайтово повторяет `EncryptionService.php:17-55`: `sodium_crypto_pwhash` (`ARGON2ID13`, ops/mem из env) + `sodium_crypto_secretbox`; длины salt/nonce — из констант VO (`EncryptedPayload.php:11-12`), сверено с Sodium в `EncryptedPayloadTest.php:23-27`. Интероп в обе стороны — `SodiumCryptographyTest.php:82-101`. |
| AC4 | выполнено | `LegacyInteropTest.php:42-88`: легаси `Paste`+`EncryptionService` читается новым адаптером и расшифровывается; новый `save()` читается и расшифровывается легаси-путём. Functional-цикл «новое создание → легаси Reveal» — `PasteFlowTest.php:36-59` без смены ожиданий + `CreateSecretFlowTest.php:161-176`. |
| AC5 | не подтверждено исполнением, соответствует статически | Миграций нет (каталог `migrations/` без изменений, `Version20260909000000.php` создаёт `paste` с теми же колонками). `config/packages/doctrine.yaml` не менялся, второй ORM-маппинг не регистрируется (ADR 0002), DBAL-адаптер пишет в `paste`. Осталось подтвердить `doctrine:schema:validate --env=test` на G3. |
| AC6 | выполнено | `CreateSecretInput.php:11-20` дословно повторяет `CreatePasteData.php:11-20` (те же `NotBlank`/`Length` и русские сообщения); functional `CreateSecretFlowTest.php:96-115` проверяет все пять текстов и `422`. `422` обеспечивает `AbstractController::doRender()` (vendor `AbstractController.php:475-482`), как в легаси. |
| AC7 | выполнено | `Hint::fromUserInput()` (Hint.php:17-32): `null`/пустое после `trim()` → `null`, иначе исходная строка; `HintTest.php:23-34`. Functional: `CreateSecretFlowTest.php:124-143`. |
| AC8 | выполнено | Лимитер `create_paste` (10/1 мин, `rate_limiter.yaml:3-7`) вызывается в `CreateSecretController.php:29-30`; в Domain/Application лимитов нет. Тест `CreateSecretFlowTest.php:145-159`: 11-я попытка → 422, ошибка, записей по-прежнему 10. |
| AC9 | выполнено | В `CreateSecretController.php` нет генерации токена, расчёта TTL, Sodium, `persist/flush`; зависимости — форма + `CreateSecretHandler` + лимитер. |
| AC10 | не подтверждено исполнением, соответствует статически | Слои `Secret_*`/`Crypto_*`/`Shared` добавлены (`deptrac.yaml:18-24`), правила ацикличны (`:36-42`), `skip_violations: {}`. Domain/Application не импортируют Symfony/Doctrine/Sodium (проверено grep: 0 совпадений); Presentation не импортирует Infrastructure. Осталось подтвердить `make deptrac` на G3. |
| AC11 | не подтверждено исполнением | `PasteController::create` удалён, `created`/`view` не изменены (diff: `src/Controller/PasteController.php`), `PasteFlowTest.php` ассерты не менялись. Устаревший `.phpunit.result.cache` содержит defect-записи по `PasteFlowTest`; требуется чистый полный прогон (Н1/Н9). |
| AC12 | не подтверждено исполнением | `tools/check-coverage.php` теперь находит `src/*/Domain` и `src/*/Application` (порог 90% активен). Все ветви VO/агрегата/сервисов/хендлера покрыты unit-тестами; ожидаемо ≥90%, но `var/coverage/clover.xml` отсутствует — подтвердить на G3. |
| AC13 | выполнено | `Secret::create` фиксирует `SecretCreated` (Secret.php:28), `pullDomainEvents()` очищает список (`:68-75`); в `src/` нет `MessageBus`/`EventDispatcher`/`dispatch` (grep). Unit: `SecretTest.php:38-57`, `CreateSecretHandlerTest.php:50-54`. |
| AC14 | выполнено | `RandomTokenGenerator.php:14-17` — `bin2hex(random_bytes(16))` = 32 hex; `UniqueTokenGenerator` повторяет генерацию при коллизии до 5 попыток, иначе `TokenGenerationFailed`; unit `UniqueTokenGeneratorTest.php`, `RandomTokenGeneratorTest.php`, `CreateSecretHandlerTest.php:57-68`. |
| AC15 | не подтверждено исполнением | Baseline отсутствует, `@phpstan-ignore` в `src/` нет, все 11 ignore — в тестах, с причиной и датой. `make qa`/`make coverage` подтвердить на G3 (Н1/Н2). |

## Соответствие plan.md

- Реализованы все заявленные шаги Ш1–Ш11; структура каталогов совпадает с
  проектируемой (плюс `tests/Unit/Secret/Infrastructure/Persistence/Doctrine/
  SecretRecordMapperTest.php` — дополнительное покрытие, не противоречит плану).
- Решения G1 реализованы как A: Q1-A (DBAL-модель `SecretRecord` + явный
  `SecretRecordMapper` + `DoctrineSecretRepository`), Q2-A (таблица `paste`, нет
  миграций), Q3-A (формат не изменён), Q4-A (целевые слои + легаси as-is),
  Q5-A (`create_paste`/`csrf_token_id`), Q6-A (`created`/`view` остаются в
  легаси; лимитер — в Presentation).
- Уточнение противоречия Q6-A (plan.md:39-47) соблюдено: у `PasteController`
  удалён только `create`, `app_paste_created` обслуживает легаси.
- Конфигурация соответствует ADR 0002: `docs/ARCHITECTURE.md` §5 и
  `docs/QUALITY.md` §2.2 обновлены; ADR переведён в `accepted` (roadmap:
  «G2 утверждён (public plan.md + ADR 0002)»).

**Отклонения от буквы плана (обоснованы):**

- `Secret_Application: [..., Psr]` (`deptrac.yaml:39`) — в проектируемом
  фрагменте `plan.md:369` `Psr` не указан. Отклонение необходимо для
  `Psr\Clock\ClockInterface` и явно зафиксировано в ADR 0002 (раздел
  «Решение»). Принято; план стоит синхронизировать (Н6).
- `Secret::reconstitute()` (`Secret.php:33-36`) отсутствует в перечне API
  агрегата (`plan.md:126-136`), но требуется для `SecretRepository::findByToken`
  и `SecretRecordMapper::fromRow`. Отклонение оправдано (Н5).
- В `config/services.yaml` дополнительно исключён `SecretRecord.php` — иначе
  DBAL-модель попала бы в DI; оправдано.
- `Cryptography::decrypt`/`DecryptionResult` реализованы, хотя production-путь
  002 их не вызывает: разрешено in-scope §4 и нужно для тестов интеропа AC3/AC4.

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- **Направление зависимостей.** Domain/Application не импортируют
  Symfony/Doctrine/Sodium. `CreateSecretHandler` зависит от портов
  (`Cryptography`, `UniqueTokenGenerator`, `SecretRepository`) и
  `Psr\Clock\ClockInterface` — допустимо (§3.3, CONVENTIONS §3).
  Presentation знает Application и доменные типы, Infrastructure не импортирует.
  Контроллер тонкий (§4 CONVENTIONS).
- **Владение портами.** `SecretRepository`/`TokenGenerator` — в
  `Secret/Domain`, `Cryptography` — в `Crypto/Domain`; адаптеры — в
  соответствующих `Infrastructure`. Реализовано верно.
- **Persistence.** ORM-атрибутов в домене нет; `SecretRecord` — `final readonly`
  без ORM (ADR 0002, §5); SQL не выходит за адаптер; миграций нет; смена СУБД —
  замена адаптера.
- **Домен.** Агрегат защищает инвариант `expiresAt = createdAt + Ttl`
  (`Secret.php:25-31`); VO самовалидируются; ошибки типизированы под
  `Secret/Domain/Exception` и наследуют `DomainException`; суффиксов
  `Manager`/`Helper` нет; use case — глагол `CreateSecret`. Замечаний нет.
- **Нейминг/термины.** Новый код использует `Secret`/`Plaintext`/`SecretKey`/
  `Hint`/`Token`/`Ttl`/`EncryptedPayload`/`Cryptography`; легаси-термин `Paste`
  не проникает в новый код. `docs/UBIQUITOUS_LANGUAGE.md` не изменён —
  новых доменных терминов нет (DoD §5).
- **Документы стандартов.** Изменения `docs/ARCHITECTURE.md` §5 и
  `docs/QUALITY.md` §2.2 оформлены через ADR 0002 (§8 ARCHITECTURE), ровно в
  объёме, заявленном планом Ш11.

## Найденные проблемы

### Блокирующие

Нет.

### Неблокирующие

**Н1. Верификация `make qa`/`make coverage`/`schema:validate` не воспроизведена
независимо.** Артефакты `var/coverage/clover.xml` и `var/phpstan` отсутствуют,
терминалы/запуск тестов в песочнице недоступны. `.phpunit.result.cache`
содержит незакрытые defects по `PasteFlowTest` (4) и
`DoctrineSecretRepositoryTest` (2), но ссылается на удалённый `FooTest`, то есть
устарел. Рекомендация: на G3 выполнить на чистом кеше
`make up && make qa && make coverage && make console doctrine:schema:validate
--env=test` и не коммитить при любом красном шаге. Ссылка: AC5/AC10/AC11/AC12/
AC15, DoD §3.

**Н2. `@phpstan-ignore` вырос с 3 до 11.** Все 11 — в `tests/`
(`PasteFlowTest` ×2, `CreateSecretFlowTest` ×3, `LegacyInteropTest` ×1,
`EncryptionServiceTest` ×1, `SodiumCryptographyTest` ×2,
`DoctrineSecretRepositoryTest` ×2), все `property.uninitialized`, все в формате
`(причина; review YYYY-MM-DD)`. **Не блокирует**: AC15 требует лишь точечность и
формат, жёсткого числового лимита в `docs/QUALITY.md`/`docs/CONVENTIONS.md` нет,
а «> ~5» из `001`/notes — критерий эскалации конкретной задачи 001, не
проектный стандарт. Причина роста системная (PHPStan `checkUninitializedProperties`
не видит инициализацию в `setUp()`); рекомендую отдельной задачей устранить
класс проблемы (базовый `TestCase` с инициализацией или
`phpstan/phpstan-phpunit`), иначе счёт будет расти с каждым тест-классом.

**Н3. Формулировка `plan.md:287` о hint неточна (замечание тестировщика «а»).**
Symfony `TrimListener` по умолчанию тримит строковые поля на wire-уровне, т.е.
`create_paste[hint] = ' x '` доходит до `CreateSecretInput` уже как `x`. Это
совпадает с легаси (`CreatePasteType` тоже без `trim => false`), наблюдаемого
изменения нет, VO-контракт «хранится verbatim» покрыт `HintTest.php:28-34`.
Рекомендуется поправить формулировку плана, чтобы не вводить в заблуждение.

**Н4. `SodiumCryptography::decrypt` отвергает пустой plaintext.**
`SodiumCryptography.php:52` вызывает `Plaintext::fromString()`, который бросает
`InvalidPlaintext` на `''`, тогда как легаси `EncryptionService::decrypt`
возвращает `''`. Через форму пустой plaintext недостижим (`NotBlank`), поэтому в
002 это не наблюдаемо, но для будущего `RevealSecret` (003) это асимметрия:
ожидаемая бизнес-альтернатива не должна становиться исключением (§6
ARCHITECTURE). Рекомендуется учесть при проектировании 003
(`DecryptedPlaintext` с допустимым пустым значением либо отдельный результат).

**Н5. `Secret::reconstitute()` не зафиксирован в плане.** Метод нужен для
чтения (`SecretRepository::findByToken` + `SecretRecordMapper::fromRow`) и не
записывает доменные события (`SecretTest.php:70-76`). Рекомендуется дополнить
API агрегата в `plan.md` для трассируемости (отклонение обосновано).

**Н6. Фрагмент deptrac в `plan.md:369` разошёлся с реализацией/ADR.**
`Psr` для `Secret_Application` есть в `deptrac.yaml:39` и ADR 0002, но не в
плане. Синхронизировать план (не блокирует: ADR — источник правды).

**Н7. `Crypto_Domain → Secret_Domain` — архитектурный компромисс.**
Generic-контекст `Crypto` зависит от ядра `Secret` (`deptrac.yaml:38`), т.к. VO
`Plaintext`/`SecretKey`/`EncryptedPayload` лежат в `Secret/Domain` (ADR 0002
раздел «Решение»). Зависимость ациклична и санкционирована принятым ADR, поэтому
не блокирует; при желании вернуть независимость — отдельный ADR о переносе VO в
`Crypto/Domain`.

**Н8. Проверка уникальности токена — TOCTOU.** `UniqueTokenGenerator` сначала
читает `tokenExists()`, затем `save()` вставляет строку. При параллельных
запросах возможна коллизия, которая упрётся в уникальный индекс `paste.token`
(`Version20260909000000.php:20`) и даст 500, а не повторную генерацию.
Поведение унаследовано от легаси; для 002 не блокирует, но стоит рассмотреть
обработку `UniqueConstraintViolationException` в 003/005.

**Н9. Изоляция rate limiter в тестах.** `PasteFlowTest::setUp()` не очищает
`cache.app`, тогда как `CreateSecretFlowTest::testEleventhRequestIsRateLimited...`
(`:145-159`) намеренно доводит лимитер до 11. Сейчас порядок тестов
(алфавитный + очистка в `CreateSecretFlowTest::setUp`) спасает, но тест
хрупкий. Рекомендуется добавить очистку `cache.app` в `PasteFlowTest::setUp`
(техническая правка теста, допустимая AC11) или изолировать пул лимитера в
test-среде. Ссылка: AC8/AC11, 001/notes follow-up №1.

**Н10. Легаси `Paste::getEncryptedPayload()` не идемпотентен (замечание
тестировщика «б»).** `Paste.php:66-80` при resource-BLOB вызывает
`stream_get_contents`, сдвигая курсор; повторный вызов вернёт пустые salt/nonce.
В 002 каждый вызов однократный, поэтому не ломается; риск проявится в 003 при
ACL-чтении. Вне объёма 002, зафиксировать в 003.

**Н11. Пробел в functional-покрытии AC7.** Нет кейса с hint в обрамляющих
пробелах на wire-уровне (есть только `''`/`'   '` и значение без пробелов).
Поведение идентично легаси, поэтому некритично; при желании добавить кейс с
`' подсказка '`, чтобы зафиксировать эффект `TrimListener`.

## Протечки абстракций между слоями

Не обнаружено:

- `Domain`/`Application` не импортируют Symfony/Doctrine/Sodium (grep — 0
  совпадений); `Psr\Clock` в Application разрешён §3.3.
- `Secret_Presentation` не импортирует `Secret_Infrastructure`/
  `Crypto_Infrastructure` (grep — 0 совпадений); только Application/Domain/
  Symfony.
- SQL/DBAL/ORM-типы локализованы в `Secret/Infrastructure/Persistence/Doctrine`;
  `Doctrine\DBAL\Connection` не выходит за адаптер.
- Легаси-взаимодействие идёт через общий контракт таблицы `paste`, а не через
  чужие внутренности: новый адаптер не использует `App\Entity\Paste`, маппинг
  остаётся единственным ORM-маппингом (ADR 0002).
- Единственный задокументированный компромисс — `Crypto_Domain → Secret_Domain`
  (Н7), санкционирован ADR 0002.

## Тестовое покрытие

Покрытие соответствует `plan.md` и `docs/QUALITY.md` §2.4 по составу:

- **Domain unit** (без контейнера): все VO (`Token`, `Ttl`, `Hint`, `Plaintext`,
  `SecretKey`, `EncryptedPayload`), агрегат (`SecretTest`), `UniqueTokenGenerator`,
  исключения/события. Проверяются инварианты, границы длин, ветви ошибок.
- **Application unit**: `CreateSecretHandlerTest` на in-memory портах и
  фиксированных `FixedClock`/`FixedTokenGenerator`, без моков VO; проверены
  ровно один `save()`, коллизия токена, проброс plaintext/secret в
  `Cryptography`, фиксация `SecretCreated`.
- **Integration**: Sodium round-trip + интероп в обе стороны с реальным
  `EncryptionService`; DBAL-репозиторий (запись/чтение, BLOB с нулевыми байтами
  и resource|string, легаси-запись); `LegacyInteropTest` в обе стороны.
- **Functional**: `CreateSecretFlowTest` (GET/POST, 302, regex токена,
  абсолютная `/p/{token}`, 422 и точные тексты, hint→null, лимитер,
  межпутевой интероп), `SecretWiringTest` (проводка портов, block prefix);
  `PasteFlowTest` не переписан — сценарии/ассерты сохранены.

Проверки интеропа **реальные**, не подогнаны: используются боевые
`SodiumCryptography`, `EncryptionService`, `DoctrineSecretRepository`,
`PasteRepository`, `EntityManager`/`SchemaTool`. Моков VO нет. Слабые места:
Н9 (изоляция лимитера), Н11 (wire-trim hint), плюс отсутствие исполнения
(Н1).

## Замечания по безопасности

- Логирования plaintext/`SecretKey`/производных ключей/salt/nonce нет: в
  `src/` нет `logger`/`error_log`/`dump`/`var_dump` (grep); `SodiumCryptography`
  логически ничего не пишет, ключ обнуляется `sodium_memzero` в `finally`
  (`SodiumCryptography.php:31-33,44-46`).
- CSRF сохранён: `csrf_token_id = 'create_paste'` (`CreateSecretType.php:28`),
  проверено `SecretWiringTest.php:42-44`.
- Rate limiting сохранён (AC8), ключ лимитера — IP, как в легаси
  (`CreateSecretController.php:28-29`).
- TTL не ослаблен: `app.secret_ttl_days` из `APP_PASTE_TTL_DAYS`
  (`config/services.yaml:2,25`), `Ttl::fromDays` требует `>= 1`.
- `Cache-Control: no-store` для Reveal не тронут (вне объёма, легаси
  `PasteController::view`).
- Крипто-формат не ослаблен: Argon2id + secretbox, штатные длины, без маркера
  версии (Q3-A, ADR 0002).
- `.env.local` не добавлен (git status), секреты не логируются; тесты
  используют нейтральные сообщения.

## Отдельные оценки, запрошенные оркестратором

1. **Рост `@phpstan-ignore` до 11 — не блокирующий.** Обоснование: AC15 не
   задаёт числовой предел, все записи точечные, с причиной и датой, идентичны
   уже принятому в 001 формату; причина системная (PHPUnit lifecycle), а не
   новая протечка. Критерий «> ~5» из `001/notes.md` — механизм эскалации
   задачи 001, а не общепроектное правило. Рекомендую вынести устранение класса
   проблемы отдельной задачей и упомянуть рост на G3.
2. **Замечание (а) TrimListener** — подтверждаю: трим на wire совпадает с
   легаси; неверна только формулировка `plan.md:287`, код и AC7 корректны (Н3).
3. **Замечание (б) неидемпотентность `Paste::getEncryptedPayload()`** —
   подтверждаю для resource-BLOB, но это легаси-дефект вне объёма 002; в
   текущих сценариях вызов однократный (Н10, риск для 003).

## Готовность к гейту G3

Условно готова: блокирующих замечаний нет, статическое соответствие AC и
стандартам подтверждено. Перед коммитом владелец G3 обязан выполнить на чистом
кеше полный `make qa`, `make coverage` и
`doctrine:schema:validate --env=test`; при любом отклонении от ожиданий AC11/
AC12/AC15 задача возвращается на доработку (Н1). Рекомендуется также принять во
внимание Н2 и Н9.
