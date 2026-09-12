# Ревью: 003-extract-reveal-secret

## Вердикт: approved

Блокирующих замечаний нет. 7 неблокирующих замечаний/рекомендаций (см. ниже).

> **Ограничение верификации.** В песочнице ревьюера инструменты PhpStorm MCP
> (`get_file_problems`/`get_inspections`) возвращают ошибку схемы ответа, запуск
> PHPUnit/`make qa` недоступен. Каталоги `var/coverage`, `var/phpstan` и
> `var/cache/test` отсутствуют, то есть независимого прогона
> `make qa`/`make coverage`/`doctrine:schema:validate --env=test` нет.
> `.phpunit.result.cache` — устаревший артефакт 002 (содержит незакрытые
> defect-записи `PasteFlowTest`/`CreateSecretFlowTest`, на которые уже указывало
> ревью 002), а не свидетельство текущего состояния. Поэтому AC, требующие
> исполнения (AC13, AC15), проверены **статически** по исходникам и конфигурации.
> Вердикт — при условии, что владелец гейта G3 выполнит полный чистый прогон
> (см. Н1). Ревьюер ничего в `src/`/`tests/`/`templates/` не менял.

## Соответствие requirements.md

Diff проверен построчно: новые `src/Secret/Application/RevealSecret/**`,
`src/Secret/Presentation/Http/{RevealSecretController,Form/RevealSecretType,Input/RevealSecretInput}.php`,
изменения `src/Crypto/Domain/DecryptedPlaintext.php`,
`src/Crypto/Infrastructure/Sodium/SodiumCryptography.php`,
`src/Controller/PasteController.php`, `config/services_test.yaml`,
`tests/**`, `docs/task/ROADMAP.md`.

| AC | Статус | Обоснование |
|---|---|---|
| AC1 | выполнено (инспекция) | `RevealSecretController.php:24` — `app_paste_view`, `/p/{token}`, `requirements: ['token' => '[a-f0-9]{32}']`, `GET,POST`; `RevealSecretType.php:26-29` — block prefix `unlock_paste`, `csrf_token_id: unlock_paste` (Form:23); шаблон `view.html.twig` без изменений. Functional: `RevealSecretFlowTest.php:65-83`. |
| AC2 | выполнено | `RevealSecretController.php:47-51` — `Revealed` → `revealed.html.twig` + `Cache-Control: no-store`; запись не мутируется (`RevealSecretHandler` только читает). Повторный Reveal и неизменность `expires_at` — `RevealSecretFlowTest.php:125-144`; exact plaintext + no-store — `:103-113`. |
| AC3 | выполнено | `RevealSecretHandler.php:39` → `InvalidKey`; контроллер `:56` — поле `secret`, «Неверный ключ», `422`, без plaintext: `RevealSecretFlowTest.php:158-168`. |
| AC4 | выполнено | Единый `NotFound` для «нет»/«истекло» (`RevealSecretHandler.php:26-28`, контроллер `:33-35,53-55`), один и тот же текст. Functional: unknown GET/POST `:189-213`, expired GET/POST `:215-243`, невалидный формат до контроллера `:96-101`. |
| AC5 | выполнено | Лимитер `unlock_paste` только в Presentation (`RevealSecretController.php:30,42-44`), ключ `(ip ?? 'unknown').':'.$token`; `rate_limiter.yaml:8-12` не менялся. Тесты: 11-я отправка `:245-262`, расход невалидными `:264-280`; проверка **до** расшифровки (11-я с верным ключом отвергнута). |
| AC6 | выполнено | Контроллер `:32-60`: только query, форма, use case, рендер; нет `SecretRepository`/QueryBuilder, `expiresAt`, Sodium/`EncryptionService`, persisted-логики. |
| AC7 | выполнено | Типизированный результат `RevealSecretResult` + `Revealed`/`InvalidKey`/`NotFound`; unit `RevealSecretHandlerTest.php` покрывает все три исхода без исключений. |
| AC8 | выполнено | `Secret::isExpiredAt` (`Secret.php:63-66`, `<=`) вызывается в Application; репозиторий `findByToken` без SQL-фильтра (`DoctrineSecretRepository.php:42-54`). Границы `expiresAt-1s / === now / +1s` — `RevealSecretHandlerTest.php:78-106`. |
| AC9 | выполнено | Reveal читает `SecretRepository` → `SecretRecordMapper` (`binaryValue` `resource\|string`, `SecretRecordMapper.php:73-91`); `App\Entity\Paste` в новом коде отсутствует. Интероп легаси→use case и новый→use case — `LegacyInteropTest.php:93-130`; HTTP-интероп — `RevealSecretFlowTest.php:103-123`. |
| AC10 | выполнено | `RevealSecretInput.php:11-13` дословно повторяет `UnlockPasteData`; `RevealSecretType.php:18` — `PasswordType`, label «Секретный ключ», `maxlength=255`; `getBlockPrefix() === 'unlock_paste'`. `SecretWiringTest.php:60-72` + functional `RevealSecretFlowTest.php:65-83,170-187`. |
| AC11 | выполнено | `PasteController.php` оставляет `created()`/`app_paste_created` и импорты `Paste`, `PasteRepository`, `Response`, `Route`, `UrlGeneratorInterface` без изменений; шаблон `created.html.twig` по-прежнему использует `paste.expiresAt`. |
| AC12 | выполнено (инспекция) | Логирования plaintext/ключа/salt/nonce нет (grep по `logger`/`dump`/`var_dump` в `src/Secret` — 0); `no-store` (`:49`), CSRF (`csrf_token_id`), нейтральные тексты, лимитер и срок сохранены. |
| AC13 | не подтверждено исполнением, соответствует статически | Слои не расширялись; Application/Domain не импортируют Symfony/Doctrine/Sodium, Presentation — Infrastructure (grep: 0). `@phpstan-ignore` 11→15 (только тесты, формат 001). Требуется прогон `make qa`/`make coverage`/`schema:validate` (Н1). |
| AC14 | выполнено | `DecryptedPlaintext` несёт `string`, `''` — успех (`DecryptedPlaintext.php:9`, `SodiumCryptography.php:52`); unit `RevealSecretHandlerTest.php:46-55`, integration `SodiumCryptographyTest.php:103-112`, HTTP `RevealSecretFlowTest.php:146-156`; неидемпотентный `Paste::getEncryptedPayload()` не используется; изоляция `cache.app` в `PasteFlowTest.php:28-31` и `RevealSecretFlowTest.php:42-45`. |
| AC15 | не подтверждено исполнением, соответствует статически | В `PasteFlowTest.php` ассерты не менялись, добавлена только очистка `cache.app`; механика 002-тестов изменена лишь для нового типа `DecryptedPlaintext->plaintext`. Требуется прогон (Н1). |

## Соответствие plan.md

- Структура каталогов совпадает с проектируемой (`plan.md:87-113`): команда,
  маркер-результат и три варианта, query `LoadRevealSecret` + `RevealSecretView`,
  оба обработчика, Presentation (контроллер/форма/input).
- Утверждённое на G2 проектное уточнение реализовано ровно как описано
  (`plan.md:50-65,195-201`): `LoadRevealSecret` покрывает AC4+AC6, набор исходов
  Reveal (`Revealed | InvalidKey | NotFound`) не расширен. Query **необходим**:
  AC6 запрещает выборку/проверку срока в контроллере, а AC4 требует нейтральный
  `404` уже на `GET` (до расшифровки).
- Решения G1 — все A: burn-after-reading не реализован, запись не мутируется
  (Q1); типизированные исходы и истечение через `Secret::isExpiredAt` (Q2);
  тотальный `decrypt` и `''` (Q3); сохранённый wire-контракт `unlock_paste` (Q4);
  чтение через новый маппер (Q5); лимитер в Presentation + изоляция `cache.app`
  (Q6); `SecretRevealed` не вводится (Q7).
- Механические правки 002-тестов (`DecryptionResultTest`,
  `SodiumCryptographyTest`, `LegacyInteropTest`) соответствуют `plan.md:236-241`.
- `PasteController::view` и его импорты удалены; `created` не затронут
  (`plan.md:313-317`). Миграций нет, схема/крипто-формат не менялись.
- `ROADMAP.md` актуализирован (`003` → `[~]`, уточнён объём, запись журнала).

**Отклонения от плана:**

| Отклонение | Оценка |
|---|---|
| `config/services_test.yaml` (public `EncryptionService` в test-env) — в плане отсутствует (`plan.md:78-80` перечисляет только неизменяемые `routes.yaml`/`services.yaml`) | Обосновано и не блокирует: `PasteController::view` был последней production-ссылкой на `EncryptionService`; после удаления сервис выпадает из графа и тесты могут его не получить. Файл test-only, prod/dev не ослабляет, HTTP-доступа не создаёт. Требует фиксации в plan/notes (Н2). |
| `RecordingCryptography` с `?DecryptionResult $decryptionResult = null` | Совпадает с `plan.md:347-350`; 002-тест `CreateSecretHandlerTest` не изменён. |
| `@phpstan-ignore` 11→15 (+4 `property.uninitialized` в `RevealSecretFlowTest.php:27-34`) | Соответствует ожидаемому из задания; формат 001 (причина + дата). Не блокирует (Н6). |
| `LegacyInteropTest` получил два новых use-case-кейса сверх плана | Дополнительное покрытие AC9, не противоречит плану. |

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- **Направление зависимостей.** `RevealSecretHandler`/`LoadRevealSecretHandler`
  зависят только от `Secret_Domain`, `Crypto_Domain` и `Psr\Clock` (§3.3).
  `RevealSecretController` импортирует Application/Domain-результаты и Symfony,
  не импортирует Infrastructure. Легаси `App\Entity\Paste`/`App\Repository`/
  `App\Service` в новом коде отсутствуют.
- **Владение портами.** Расширение толькочтением: `SecretRepository::findByToken`
  уже в `Secret/Domain`, адаптер — в `Secret/Infrastructure`; порт не изменён,
  SQL не выходит за адаптер (ADR 0002 соблюдён). `Cryptography` — в
  `Crypto/Domain`, Sodium-адаптер — в `Crypto/Infrastructure`.
- **Домен.** Истечение — политика агрегата (`Secret::isExpiredAt`), наружу отдан
  типизированный результат, а не `null`/исключение (§6 ARCHITECTURE).
  `Manager`/`Helper`/`Util` нет; use case назван глаголом.
- **Presentation.** Контроллер тонкий (CONVENTIONS §4): форма + use case + рендер,
  `persist/flush` и бизнес-логики нет.
- **Термины.** Новый код использует `Secret`/`Reveal`/`Plaintext`/`SecretKey`/
  `Hint`/`Token`; термин `Paste` в новом коде не появляется.
  `docs/UBIQUITOUS_LANGUAGE.md` не изменён — новых доменных терминов нет.
- **Ошибки.** Ожидаемые альтернативы (`InvalidKey`, `NotFound`) — результат, а не
  исключение; HTTP-маппинг (`404`/`422`) локализован в Presentation.

## Найденные проблемы

### Блокирующие

Нет.

### Неблокирующие

**Н1. Прогон `make qa`/`make coverage`/`schema:validate` не воспроизведён
независимо.** Отсутствуют `var/coverage/clover.xml`, `var/phpstan`,
`var/cache/test`; инструменты инспекций недоступны. Рекомендация: на G3 на чистом
кеше выполнить `make up && make qa && make coverage && make console
doctrine:schema:validate --env=test` и не коммитить при красном шаге. Ссылка:
AC13/AC15, DoD.

**Н2. `config/services_test.yaml:1-9` добавлен вне плана.**
Правомерность: изменение test-only, не ослабляет prod/dev, не создаёт
HTTP-доступ и объективно необходимо, потому что после удаления
`PasteController::view` `App\Service\EncryptionService` теряет последнюю
production-ссылку. Хрупкость: в файле повторён `_defaults` **без
`autoconfigure`** и только с частью bind'ов (`$configuredOpsLimit`/
`$configuredMemLimit`, но не `$unlockPasteLimiter`/`Psr\Clock\ClockInterface`);
это безопасно для одного `EncryptionService`, но будущие test-only сервисы в
этом файле не унаследуют остальные bind'ы. Также вероятно, что Symfony и без
этого файла отдаёт приватный сервис через `test.private_services_locator`
(`TestServiceContainerWeakRefPass` до удаления неиспользуемых определений).
Рекомендация: либо удалить файл и подтвердить зелёный прогон, либо явно
зафиксировать причину в `plan.md`/`notes.md` и дополнить `_defaults`. Не
блокирует.

**Н3. Артефакт плана рассинхронизирован с гейтом.** `plan.md:3` всё ещё
«ожидает G2», в журнале `ROADMAP.md` записан только G1 задачи 003, хотя
`LoadRevealSecret` утверждён на G2. Рекомендуется обновить статус и запись
журнала для трассируемости. Не блокирует.

**Н4. Дублирование гейта «найти + `isExpiredAt`».** `RevealSecretHandler.php:25-28`
и `LoadRevealSecretHandler.php:21-24` повторяют один и тот же предикат. Сейчас это
две точки правки семантики срока; при росте числа read-сценариев стоит вынести
маленькую доменную политику/функцию. Прагматично и допустимо по §4.2. Не
блокирует.

**Н5. Неисчерпывающий разбор `DecryptionResult`.** `RevealSecretHandler.php:35-39`
после `instanceof DecryptedPlaintext` трактует **любой** иной результат как
`InvalidKey`; при добавлении третьего варианта он молча будет считаться неверным
ключом. Порт сейчас замкнут на два варианта, поэтому не блокирует; при расширении
`DecryptionResult` рассмотреть `match` с явными ветвями.

**Н6. `@phpstan-ignore` вырос с 11 до 15** — 4 новых в
`RevealSecretFlowTest.php:27-34`, все `property.uninitialized`, с причиной и
датой (формат 001). Жёсткого лимита в `docs/QUALITY.md`/`CONVENTIONS.md` нет,
потому не блокирует. Причина системная (PHPStan не видит инициализацию в
`setUp()`); устранение класса проблемы — отдельной задачей (базовый `TestCase`
или `phpstan/phpstan-phpunit`).

**Н7. Двойное чтение Secret на POST.** `RevealSecretController.php:32,46`:
`LoadRevealSecret` + `RevealSecret` дают два `findByToken`. Это осознанно
заложено планом (`plan.md:460`), оправдано паритетом порядка «404 → лимит →
валидация → расшифровка» и закрытием гонки «истекло между запросами» (второе
чтение вернёт `NotFound` → нейтральный `404`). Дополнительный индексированный
lookup. Не блокирует.

## Протечки абстракций между слоями

Не обнаружено:

- `src/Secret/Domain` не импортирует Symfony/Doctrine/Sodium (grep — 0).
- `src/Secret/Application/RevealSecret` не импортирует Symfony/Doctrine/Sodium;
  единственные внешние типы — `Psr\Clock\ClockInterface` и порты `Secret`/
  `Crypto` (разрешено §3.3/ADR 0002).
- `src/Secret/Presentation/Http` не импортирует `Infrastructure`/`App\Entity`/
  `App\Repository`/`App\Service`; контроллер знает только Application и Symfony.
- SQL/DBAL локализованы в `Secret/Infrastructure/Persistence/Doctrine`; Reveal
  читает через порт `SecretRepository`, а не через QueryBuilder/ORM-сущность.
- Легаси `App\Entity\Paste` в Reveal не участвует (Q5-A/Н10); неидемпотентный
  `Paste::getEncryptedPayload()` для нового пути недостижим.

## Тестовое покрытие

Покрытие соответствует `plan.md` и `docs/QUALITY.md` §2.4 по составу:

- **Application unit** (`tests/Unit/Secret/Application/RevealSecret/`): in-memory
  репозиторий, `FixedClock`, `RecordingCryptography` — без моков VO/классов.
  Все три исхода, пустой plaintext, отсутствие расшифровки на `NotFound` и
  граница `expiresAt-1s/=== now/+1s`; проверено, что в `Cryptography` уходят
  payload агрегата и `SecretKey` команды.
- **Integration**: `SodiumCryptographyTest.php:103-112` — регресс пустого
  plaintext из легаси `EncryptionService`; `LegacyInteropTest.php:93-130` —
  легаси-запись и запись 002 открываются application use case (реальные
  `DoctrineSecretRepository`, `SecretRecordMapper`, `Paste`, `EntityManager`).
- **Functional** (`RevealSecretFlowTest.php`): форма/hint/wire-контракт, успех +
  `no-store`, повторный Reveal и неизменность `expires_at`, неверный ключ,
  пустой/длинный ключ, unknown/expired `GET`/`POST` с единым текстом, невалидный
  формат токена, лимитер (11-я до расшифровки; невалидные расходуют), интероп
  легаси→HTTP. `SecretWiringTest.php` — проводка обработчиков, prefix/CSRF,
  маршрут. `PasteFlowTest.php` — ожидания не изменены (AC15), добавлена изоляция
  `cache.app`.

Интероп-тесты реальные, без подгонки: используются боевые Sodium-адаптер,
`EncryptionService`, DBAL-репозиторий и ORM-сущность. Моков VO нет. Слабые
места — только отсутствие независимого прогона (Н1) и изоляция лимитера через
`cache.app->clear()` (принято Q6-A).

## Замечания по безопасности

- Логирования plaintext/`SecretKey`/производных ключей/salt/nonce нет: в
  `src/Secret` и `src/Crypto` нет `logger`/`error_log`/`dump`/`var_dump`
  (grep — 0); ключ обнуляется `sodium_memzero` в `finally`
  (`SodiumCryptography.php:42-46`).
- `Cache-Control: no-store` на успешном Reveal сохранён
  (`RevealSecretController.php:49`) и проверен тестом.
- CSRF сохранён (`csrf_token_id: unlock_paste`,
  `RevealSecretType.php:23`) и проверен `SecretWiringTest.php:71`.
- Нейтральность `404` для «нет записи»/«истекло» и `422` для неверного ключа не
  нарушена (единый `NotFound`, одинаковый текст; plaintext не показывается).
- Rate limiting не ослаблен: лимитер `unlock_paste` 10/1 мин, ключ
  `(ip ?? 'unknown').':'.$token`, проверка до расшифровки; в
  `Domain`/`Application` лимитера нет.
- Проверка срока не ослаблена: `expiresAt <= now` эквивалентна SQL
  `expiresAt > :now` легаси (точное равенство = истекло); подтверждено unit- и
  functional-границами.
- `config/services_test.yaml` не влияет на prod/dev и не открывает сервис по
  HTTP; крипто-формат и схема БД не менялись; `.env.local` не добавлен.

## Отдельные оценки, запрошенные оркестратором

1. **Правомерность `config/services_test.yaml` вне плана.** Правомерно как
   test-only мера после удаления последней production-ссылки на
   `EncryptionService`; prod/dev не затрагивает (Н2). Рекомендую зафиксировать
   причину в плане/notes; возможно, файл избыточен из-за
   `test.private_services_locator`.
2. **Хрупкость `_defaults` без `autoconfigure`.** Для одного
   `EncryptionService` несущественно, но шаблон хрупок для будущих test-only
   сервисов (нет остальных bind'ов). Не блокирует (Н2).
3. **Необходимость query `LoadRevealSecret`.** Необходима: без неё нельзя
   одновременно выполнить AC6 (тонкий контроллер) и AC4 (нейтральный `404` на
   `GET`). Реализована точно по G2; набор исходов Reveal не расширен.
4. **Риск `expiresAt <= now` vs SQL `expiresAt > now`.** Эквивалентны
   (отрицание того же предиката). Учёт микросекунд и формата дат не создаёт
   расхождения: Doctrine форматирует `:now` без микросекунд, PHP-сравнение даёт
   тот же результат на границе. Подтверждено unit-тестами границы.
5. **Пустой plaintext (Q3-A).** Реализован как успех со значением `''` на всех
   трёх уровнях (unit/integration/functional); инвариант «непустой» остался
   только на создании (`Plaintext` для `encrypt`).
6. **Повторный Reveal / отсутствие burn-after-reading (Q1-A).** Запись не
   удаляется и не помечается; `RevealSecretFlowTest.php:125-144` фиксирует
   повторный успех и неизменность `expires_at`.
7. **Границы и легаси.** `App\Entity\Paste`, `PasteRepository`,
   `UnlockPasteType`/`UnlockPasteData`, `EncryptionService` сохранены до 005;
   production-путь Reveal их не использует; `created` не затронут.

## Готовность к гейту G3

Условно готова: блокирующих замечаний нет, статическое соответствие AC,
`requirements.md`, `plan.md`, `ARCHITECTURE.md`/`CONVENTIONS.md` и ADR 0002
подтверждено; новых слоёв, миграций, доменных событий и терминов нет. Перед
коммитом владелец G3 обязан выполнить на чистом кеше полный `make qa`,
`make coverage` и `doctrine:schema:validate --env=test`; при отклонении задача
возвращается на доработку (Н1). Рекомендуется принять во внимание Н2 и Н6.
