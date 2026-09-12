# Ревью: 007-extract-access-rate-limiting

## Вердикт: approved

Задача реализует решения G1 (Q1–Q6 = A): механизм rate limiting вынесен из
`Secret/Presentation` в новый контекст `Access` за framework-free портом, при
сохранении наблюдаемого поведения. Блокирующих замечаний нет.

## Методика и ограничения

- Проверены `requirements.md`, `plan.md`, рабочий diff (uncommitted),
  `deptrac.yaml`, `docs/QUALITY.md` §2.2, конфиги, `src/Access/**`,
  `src/Secret/Presentation/Http/*`, новые и существующие тесты.
- Symfony API сверен с Context7 (Symfony 7.4): `RateLimiterFactory` принимает
  `array $config` + `StorageInterface`, а `->create($key)->consume()->isAccepted()`
  — корректный вызов. Совпадает с `src/Access/Infrastructure/Symfony/SymfonyAccessLimiter.php:22`.
- **Ограничение среды:** в рабочем окружении ревью нет `vendor`/Docker, поэтому
  `make qa` (cs-check, PHPStan L10, deptrac, PHPUnit) и `make coverage` не
  запускались; их результат оценён статически по конфигам, правилам deptrac и
  коду. Фактический прогон остаётся за гейтом G3.

## Соответствие requirements.md

| AC | Статус | Обоснование |
|---|---|---|
| AC1 — контекст `Access` изолирован | ✅ | `src/Access/Domain/{AccessLimiter,AccessLimit,AccessDecision}.php` + `src/Access/Infrastructure/Symfony/SymfonyAccessLimiter.php`. Грепом: `Symfony\Component\RateLimiter` в `src/` встречается только в `Access/Infrastructure`; `Secret/{Domain,Application}` не ссылаются на `App\Access`; `Secret/Presentation` не импортирует `RateLimiterFactory` и `Access\Infrastructure` |
| AC2 — Create: лимит и момент `consume` | ✅ | `CreateSecretController.php:29-33`: `consume` внутри `if ($form->isSubmitted())` до `isValid()`, ключ `getClientIp() ?? 'unknown'`, текст дословно. Покрыто существующим `CreateSecretFlowTest::testEleventhRequestIsRateLimitedAndCreatesNoExtraRecord` и новыми `AccessRateLimitFlowTest::testRepeatedHomePageGetsDoNotConsumeCreateLimit`, `testInvalidCreateSubmissionsConsumeLimitBeforeValidation`, `testCreateLimitIsScopedToClientIp` |
| AC3 — Reveal: лимит, ключ, момент `consume` | ✅ | `RevealSecretController.php:43-47`: ключ `(ip ?? 'unknown').':'.$token`, `consume` до `isValid()`, текст дословно. Покрыто `RevealSecretFlowTest` (10/11) и `AccessRateLimitFlowTest::testRevealLimitIsScopedToToken`, `testRevealLimitIsScopedToClientIp` |
| AC4 — порядок проверок | ✅ | Reveal: гейт `LoadRevealSecret`/`404` (`:34-37`) до формы и `consume`. `AccessRateLimitFlowTest::testUnknownTokenReturns404WithoutConsumingRevealLimit`, `testExpiredTokenReturns404WithoutConsumingRevealLimit`; `RevealSecretFlowTest::testEleventhWrongAttemptIsRateLimitedBeforeDecryption` (plaintext не показывается) |
| AC5 — лимиты/политика/хранилище | ✅ | `config/packages/rate_limiter.yaml` не изменён (не в diff): обе фабрики `fixed_window`, `limit: 10`, `interval: '1 minute'`, `cache_pool: cache.app`. Порт резолвится в адаптер (`config/services.yaml:29`). Значения не дублируются в env/Domain |
| AC6 — HTTP-контракт, тексты, `422` | ✅ | Тексты и `422` через `AbstractController::doRender()` не менялись; `429`/`Retry-After` не введены; маршруты/формы/`csrf_token_id` не тронуты (`SecretWiringTest` без изменений) |
| AC7 — границы deptrac | ✅ (статически) | `deptrac.yaml:17-18,29-32`: `Access_Domain: []`, `Access_Infrastructure: [Access_Domain, Symfony, Shared]`, `Secret_Presentation: [..., Access_Domain]`; `Secret_Domain`/`Secret_Application` не содержат `Access_*`, значит зависимости запрещены; `Access_Infrastructure` не разрешён из `Secret_Presentation`. `docs/QUALITY.md` §2.2 синхронизирован дословно |
| AC8 — тесты без смены поведения | ✅ (статически) | Добавлены unit-тесты адаптера, structural guard, wiring-тест и HTTP-тесты порядка/ключей. Существующие `CreateSecretFlowTest`, `RevealSecretFlowTest`, `PasteFlowTest`, `SecretWiringTest` **не изменены** (git status). Мёртвого двойника `RecordingAccessLimiter` нет (опциональный Q5-B не реализован) |
| AC9 — безопасность и обратимость | ✅ | Через порт идут только `string $key` и enum; логирования нет; `plaintext`/ключи/salt/nonce в `Access` не попадают; `.env.local` не добавлен; миграций нет |
| AC10 — документы | ✅ | `requirements.md`/`plan.md` на месте, `review.md` — этот файл; `ROADMAP.md:42,138` отражает `[~]`/G1; `UBIQUITOUS_LANGUAGE.md` не изменён; ADR не создавался (Q4-A) |

## Соответствие plan.md

- Структура `Access` ровно как в плане (§«Проектируемая структура»): порт +
  два enum без поведения + Symfony-адаптер.
- Порядок и место `consume` в обоих контроллерах совпадают с планом
  (`CreateSecretController.php:29-33`, `RevealSecretController.php:43-47`).
- DI: bindings `$createPasteLimiter`/`$unlockPasteLimiter` удалены, `Access/Domain`
  исключён из авторегистрации, добавлены алиас порта и **явные** named-arguments
  `$createSecretLimiter`/`$revealSecretLimiter` (`config/services.yaml:19,29-33`) —
  неоднозначный автовайринг `RateLimiterFactory` исключён.
- `rate_limiter.yaml` не изменён — единственный источник значений.
- deptrac и `docs/QUALITY.md` §2.2 зеркальны. Порядок правок соответствует
  шагам Ш1–Ш5 плана.
- Отклонения от плана: опциональный Q5-B (`RecordingAccessLimiter` +
  Presentation-тест) не реализован — план это допускал; порядок `consume`
  покрыт HTTP-тестами.

## Соответствие ARCHITECTURE.md и CONVENTIONS.md

- §3.2/§3.3: `Access/Domain` framework-free; зависимости направлены верно
  (`Secret_Presentation → Access_Domain`, адаптер `→ Access_Domain + Symfony`);
  между модулями виден только опубликованный порт.
- §4.2: `Access` минимален — интерфейс + два enum + адаптер; VO/политик/событий
  нет, преждевременного усложнения нет.
- §5: Doctrine/персистентность не затрагиваются; SQL в `Domain`/`Application` нет.
- §6/CONVENTIONS §6: `AccessDecision` — типизированный результат вместо `bool`;
  доменных исключений здесь не требуется, ожидаемая альтернатива — результат.
- CONVENTIONS §3: `Access/Domain` не импортирует Symfony/Doctrine/Sodium;
  `Secret/Presentation` не импортирует `Access/Infrastructure`.
- CONVENTIONS §4: тонкие контроллеры, DI через конструктор/аргументы, маршруты
  атрибутами, конфигурируемые значения в YAML.
- CONVENTIONS §1/§2: `declare(strict_types=1)`, `final readonly`, PascalCase,
  осмысленные доменные имена без `Manager`/`Helper`.

## Найденные проблемы

### Блокирующие

Нет.

### Неблокирующие

- **N1 (предложение).** `tests/Unit/Access/Architecture/RateLimiterBoundaryTest.php:53-66`:
  `testSecretLayersDoNotDependOnAccessInfrastructure` не проверяет, что
  просмотрено хотя бы N файлов `Secret/`, поэтому при исчезновении каталога тест
  прошёл бы вакуумно. Аналогичные тесты `:15-33` и `:35-51` такие проверки
  содержат. Не влияет на текущий результат; можно добавить счётчик.
  *Основание: качество структурного guard, а не требование AC.*
- **N2 (предложение).** `tests/Functional/AccessWiringTest.php:21-43` дублирует
  значения `rate_limiter.yaml` в ожиданиях. Для задачи-инварианта 007 это
  оправданный «замороженный» контракт, но при плановом изменении лимитов тест
  придётся править синхронно. Альтернатива — читать скомпилированный конфиг
  фреймворка. *Основание: удобство сопровождения.*
- **N3 (предложение).** `src/Access/Domain/AccessLimit.php:9-10`: кейсы
  `CreateSecret`/`RevealSecret` называют лимиты именами сценариев `Secret`.
  Кодовой зависимости `Access → Secret` нет, deptrac чист, решение
  архитектора (Q3-A) это допускает; на будущее (квоты/`Identity`) нейтральные
  имена могли бы уменьшить сцепление по словарю.
- **N4 (риск, вне объёма).** `getClientIp()` и trusted proxies: поведение
  сохранено как есть (Q6-A), изменения вне 007. Риск зафиксирован в
  requirements/plan; новых утечек не появилось.
- **N5 (предложение).** `tests/Functional/AccessRateLimitFlowTest.php:52-58`
  повторяет bootstrap `SchemaTool` из существующих functional-тестов. Это
  дублирование тестовой инфраструктуры (в requirements — отдельная будущая
  задача, N2–N8 005/006), а не регресс 007.

## Протечки абстракций между слоями

Протечек не обнаружено:

- `Symfony\Component\RateLimiter` — только `src/Access/Infrastructure/Symfony/SymfonyAccessLimiter.php`.
- `App\Access\Infrastructure` из `Secret/*` не импортируется.
- `Secret/Domain`, `Secret/Application` не зависят от `Access`.
- `Access` не импортирует `Secret`/`Crypto`; строки `create_paste`/`unlock_paste`
  остались только в config/services (и legacy-именах форм), в `Access/Domain` их нет.
- Обращение к внутренностям чужого модуля отсутствует: виден только порт.

## Тестовое покрытие

- **Unit адаптера** (`SymfonyAccessLimiterTest`): 10 accepted / 11 rejected для
  обоих лимитов; независимость ключей; независимость лимитов на одном ключе;
  маппинг limit → своя фабрика (create 2 / reveal 3). Детерминированно на
  `InMemoryStorage`.
- **Structural guard** (`RateLimiterBoundaryTest`): изоляция namespace,
  framework-free `Access/Domain`, отсутствие `Access/Infrastructure` в `Secret`.
- **Wiring** (`AccessWiringTest`): порт → `SymfonyAccessLimiter`; точная
  конфигурация обеих фабрик.
- **HTTP** (`AccessRateLimitFlowTest`): GET не расходует лимит; невалидные
  отправки расходуют (consume до валидации); независимость create/reveal,
  token, IP; `404` до `consume` для unknown/expired.
- **Регрессионный барьер**: `CreateSecretFlowTest`, `RevealSecretFlowTest`,
  `PasteFlowTest`, `SecretWiringTest` не изменены.
- Покрытие `src/*/{Domain,Application}` не страдает: `Access/Domain` — только
  интерфейс и enum без исполняемых statements (`tools/check-coverage.php:56-59`
  пропускает нулевые statements); поведение адаптера покрыто unit-тестом.

## Замечания по безопасности

- Лимиты не ослаблены: те же 10/1 мин, `fixed_window`, `cache.app`, те же ключи
  `ip` и `ip:token`; порядок `consume` сохранён.
- `plaintext`, `SecretKey`, производные ключи, salt/nonce в `Access` не
  передаются и не логируются; логирования в новом коде нет.
- CSRF, `Cache-Control: no-store`, проверка срока и `422` не затронуты;
  HTTP `429`/`Retry-After` не введены.
- `.env.local` не добавлен; секреты/конфиги не расширены; миграций нет —
  изменение локально и обратимо.
