# Ревью: 005-remove-legacy-paste

## Вердикт: approved

> **Повторное ревью (после B1).** Первичное ревью дало `changes-requested` из-за
> B1 (`final` ORM-якорь ломал prod `cache:warmup`). Блокер снят: `final` удалён,
> `PasteSchemaAnchor` снова проксируем, prod warmup подтверждён разработчиком
> (`APP_ENV=prod php bin/console cache:warmup` → OK). N1 закрыт (заглушки
> удалены). Блокирующих замечаний не осталось.

Проверка выполнена статически: чтение diff/реализации, конфигов, ADR и
стандартов, сверка с vendor-реализациями Doctrine ORM 3.7 / DoctrineBundle
2.19 / Symfony VarExporter. Запуск `make qa`/`make coverage`/Docker в песочнице
ревьюера недоступен (ограничен shell), поэтому выводы по гейтам даны по коду и
конфигурации, а также по подтверждённым разработчиком прогонам
(`make qa` — 149 tests, `make coverage` — 100%,
`doctrine:schema:validate --env=test` — зелёный,
`APP_ENV=prod cache:warmup` — OK).

Задача выполнена корректно: перенос `created` в модуль `Secret`, удаление
легаси-классов, schema-anchor, целевой deptrac и синхронизация документов
сделаны аккуратно; единственный блокер первого прохода устранён.

### Проверка исправления B1 (независимо)

- `src/Secret/Infrastructure/Persistence/Doctrine/Schema/PasteSchemaAnchor.php:12`
  — теперь `class PasteSchemaAnchor` (модификатор `final` убран); атрибуты
  `#[ORM\Entity]`, `#[ORM\Table(name: 'paste')]`, `#[ORM\Index(...)]` и свойства
  не изменились.
- Единственный ORM-класс в `src/` — `PasteSchemaAnchor`; других ORM-сущностей
  нет. Значит, `ProxyCacheWarmer` не встретит `final`-класс.
- Механизм, ломавший warmup, снят: `ProxyHelper::generateLazyGhost()` бросает
  только на `final`/readonly/private(set)-классах
  (`vendor/symfony/var-exporter/ProxyHelper.php:39-41`); класс теперь non-final,
  свойства `public` и не `readonly`.
- Регрессий mapping/DDL нет: `config/packages/doctrine.yaml` не менялся
  относительно первичного ревью (тот же `SecretSchema` → каталог `Schema`),
  `migrations/` не тронут (`git diff -- migrations/` пуст), состав ORM-полей
  прежний.

## Соответствие requirements.md

| AC | Статус | Обоснование |
|---|---|---|
| AC1 | выполнено | `SecretCreatedController.php:16-27` — маршрут `app_paste_created`, `/created/{token}`, `[a-f0-9]{32}`, `GET`; `#paste-url` абсолютный, `expiresAt` в `d.m.Y, H:i`. `SecretCreatedFlowTest` + `SecretWiringTest::testCreatedRouteKeepsLegacyNameAndUrl`. |
| AC2 | выполнено (см. N4) | `SecretCreatedController.php:20-22` → `createNotFoundException()`; неизвестный токен → 404, невалидный — отсекается requirement'ом. Тест проверяет только статус, не нейтральность тела. |
| AC3 | выполнено | Срок в `created` не проверяется (`findByToken` без фильтра). `testExpiredSecretStillRendersCreatedPage`. |
| AC4 | выполнено | `src/` не содержит удалённых классов (grep пуст); `config/services_test.yaml` удалён; `LegacyRemovalTest` — негативные `class_exists`. |
| AC5 | выполнено (см. N3) | `/` и `/p/{token}` не менялись; wire-контракты/статусы/тексты/`no-store`/лимиты сохранены; изменён только способ seeding'а. Один HTTP-кейс пустого plaintext удалён — согласован планом. |
| AC6 | выполнено | Контроллер — только `findByToken` + view-модель + render; в шаблон идут лишь `expiresAt` и URL. `SecretCreatedView` без payload. |
| AC7 | выполнено (статически) | `deptrac.yaml` — целевой ruleset, бизнес-слои `App\*` удалены, `Kernel/Schedule/EventSubscriber` сохранены; их импорты не выходят за правила. |
| AC8 | выполнено | `migrations/` не тронут; маппинг `SecretSchema` описывает `paste` метаданными, структурно эквивалентными удалённой `App\Entity\Paste`; DDL/индексы не меняются. Прокси-совместимость якоря — см. B1 (устранён). |
| AC9 | выполнено | `LegacyInteropTest`/`EncryptionServiceTest` удалены; легаси-ветки Sodium убраны; seeding/проверки через `SecretRepository`/`Cryptography`; негативные ссылки — только в guard-тестах. |
| AC10 | подтверждено прогоном разработчика | `make qa` (cs-check, PHPStan L10, deptrac, 149 tests) и `make coverage` (100%) зелёные; новых `@phpstan-ignore` нет, ignore = 11. Ревьюер прогон не дублировал. |
| AC11 | выполнено | В `created` не рендерятся `hint/plaintext/salt/nonce/ciphertext` (leak-тесты); CSRF/rate limiting/`no-store`/срок не ослаблены. |
| AC12 | выполнено, кроме N5/N8 | ADR 0003 `accepted`; `ARCHITECTURE.md` §5 и `QUALITY.md` §2.2 обновлены; `ROADMAP.md` (`005` → `[~]`, `006`/`007`/`008`) обновлён; `UBIQUITOUS_LANGUAGE.md` не изменён. `plan.md:3,6,120` остаётся со статусом «ожидает G2»/`proposed`/`final` — N5, N8. |

## Соответствие plan.md

- Ш1–Ш5 реализованы по структуре: новый `SecretCreatedController`/`SecretCreatedView`,
  `PasteSchemaAnchor` + mapping `SecretSchema`, удаление ORM/DTO/Form/Service,
  целевой deptrac, документы.
- Отклонения и их оценка:
  - (а) `PasteSchemaAnchor` использует `public`-свойства с дефолтами
    (`token = ''`, nullable `createdAt/expiresAt`). Проверено: `AttributeDriver`
    не выводит nullable из PHP-типа (`vendor/doctrine/orm/src/Mapping/Driver/AttributeDriver.php:750`,
    `Mapping/Column.php:27` — `nullable = false`). DDL/nullability не искажаются.
    Однако сигнатура класса теперь «nullable», тогда как колонки `NOT NULL` —
    см. N6.
  - (б) `PasteSchemaAnchorTest::testSchemaIsInSyncWithMetadata()` ставит
    `schema_assets_filter` на `paste`. Дрейф самой `paste` при этом **не**
    маскируется (она включена в фильтр); отсекаются лишь служебные таблицы
    (`doctrine_migration_versions`). См. N2 о реальной ценности теста.
  - (в) `.gitignore` в `src/Controller|Entity|Repository` и пустой `src/Command/`
    были оставлены в первом проходе; в повторном — удалены (N1 закрыт).
- `plan.md:120` всё ещё содержит сниппет `final class PasteSchemaAnchor`, хотя
  код уже non-final. Это документационный остаток B1 (см. N8): сам блокер снят в
  коде, но план/сниппет следует поправить, чтобы не воспроизводить ошибку.

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- Направление зависимостей соблюдено: `SecretCreatedController` →
  `Secret_Domain` + Symfony (Presentation); `PasteSchemaAnchor` →
  Doctrine (Infrastructure); `Domain`/`Application` не тронуты.
- ORM-маппинг не живёт на доменном агрегате; якорь — технический артефакт в
  `Infrastructure` (ARCHITECTURE §5, ADR 0003).
- DI: `Schema/` и `SecretCreatedView.php` исключены из `App\:`-авторегистрации;
  построение графа — в `config/services.yaml`.
- Расхождение с буквой CONVENTIONS §1 (`final` по умолчанию) для ORM-якоря
  осознанное и корректное: проксируемая ORM-сущность не может быть `final` без
  native lazy objects. В первом проходе это было нарушено (B1), теперь исправлено
  (`class PasteSchemaAnchor`).

## Найденные проблемы

### Устранённые блокирующие (первый проход)

**B1 (устранён). `PasteSchemaAnchor` был объявлен `final`, из-за чего падал
`cache:warmup` в `--env=prod` (production-сборка/деплой).**

Статус: **снят**. `final` удалён (`PasteSchemaAnchor.php:12`), prod warmup
подтверждён разработчиком. Ниже сохранён разбор первопричины как обоснование,
почему именно это было блокером.

- Локализация: `src/Secret/Infrastructure/Persistence/Doctrine/Schema/PasteSchemaAnchor.php:12`
  (`final class PasteSchemaAnchor`), при `config/packages/doctrine.yaml:13-18`
  (маппинг `SecretSchema`, класс попадает в ORM-метаданные) и
  `config/packages/doctrine.yaml:23` (`when@prod: auto_generate_proxy_classes: false`).
- Механизм: в prod `ProxyCacheWarmer` не пропускает генерацию
  (`vendor/symfony/doctrine-bridge/CacheWarmer/ProxyCacheWarmer.php:56-62`),
  вызывает `ProxyFactory::generateProxyClasses()`, который для каждой
  ORM-сущности строит lazy-ghost прокси. `ProxyHelper::generateLazyGhost()`
  бросает `LogicException: Cannot generate lazy ghost: class "..." is final.`
  (`vendor/symfony/var-exporter/ProxyHelper.php:39-41`). `ProxyFactory::skipClass()`
  пропускает только mapped superclass/embedded/abstract
  (`vendor/doctrine/orm/src/Proxy/ProxyFactory.php:322-327`), `final`/read-only —
  нет. Native lazy objects не включены
  (`enable_native_lazy_objects` default `false`, в `config/` не задан), поэтому
  путь генерации прокси активен.
- Триггер: `Dockerfile:32` — `php bin/console cache:warmup` при
  `APP_ENV=prod APP_DEBUG=0` (`Dockerfile:25`). Сборка prod-образа (CI job
  `build`, `deploy`) завершится ошибкой.
- Почему не поймано гейтами: `make stan` делает `cache:warmup --env=dev`, а в
  dev `auto_generate_proxy_classes: true` → `ProxyCacheWarmer` пропускает
  генерацию; `doctrine:schema:validate`/`SchemaTool` прокси не создают. То есть
  при зелёном `make qa` релиз остаётся сломанным. Удалённая `App\Entity\Paste`
  была **не** `final` именно по этой причине.
- Как исправлено: убран модификатор `final` у `PasteSchemaAnchor` (выбран
  вариант 1 — минимальный). Проверка: `APP_ENV=prod APP_DEBUG=0 php bin/console
  cache:warmup` завершается успешно (подтверждено разработчиком). Осталось
  поправить сниппет `plan.md:120` (см. N8).

### Неблокирующие

**N1 (закрыт). Каталоги легаси остались как пустые заглушки.**
Статус: **закрыт**. Удалены `src/Controller/.gitignore`, `src/Entity/.gitignore`,
`src/Repository/.gitignore`; каталоги `src/Controller`, `src/Entity`,
`src/Repository`, `src/Command` больше не существуют (в `src/` остались только
`Crypto/`, `EventSubscriber/`, `Secret/`, `Kernel.php`, `Schedule.php`).

**N2 (остаётся, неблокирующее). `PasteSchemaAnchorTest` проверяет физическую
таблицу, созданную из тех же метаданных.**
Локализация: `tests/Integration/Secret/Infrastructure/Persistence/Doctrine/PasteSchemaAnchorTest.php:78-99`.
`PRAGMA table_info(paste)` после `SchemaTool::createSchema()` сравнивается с
метаданными — сравнение почти тавтологично (names only, без типов/nullability).
Реальная защита от дрейфа `anchor ↔ миграция` — только CI
`doctrine:schema:validate --env=test` (сравнение с результатом миграции).
Предложение: явно зафиксировать это в комментарии либо добавить проверку
nullable/типов против миграционно-созданной схемы.

**N3 (остаётся, неблокирующее). Потеря integration-покрытия пустого plaintext.**
Удалён `RevealSecretFlowTest::testEmptyPlaintextIsRevealedAsSuccessOverHttp`;
в `SodiumCryptographyTest` также удалён
`testEmptyPlaintextEncryptedByLegacyServiceIsDecryptedAsSuccess`. Остаётся
только application-тест с fake `RecordingCryptography`
(`tests/Unit/Secret/Application/RevealSecret/RevealSecretHandlerTest.php:46`), не
проверяющий реальный `SodiumCryptography::decrypt` на пустом plaintext. Ветка
крипто-адаптера не имеет special-case, риск низкий, и удаление согласовано
планом; но план сам предусматривал fallback — фикстуру фиксированных байтов.
Предложение: добавить в `SodiumCryptographyTest` round-trip с фиксированным
ciphertext'ом пустого plaintext.

**N4 (остаётся, неблокирующее). AC2 проверен частично.**
`SecretCreatedFlowTest::testUnknownTokenReturnsNeutral404` /
`testMalformedTokenReturnsNeutral404` проверяют только код 404, но не то, что
тело нейтрально («детали не раскрываются»). Предложение: добавить assertion на
отсутствие маркеров записи/подсказки в теле 404.

**N5 (остаётся, неблокирующее). Документация частично устарела.**
`docs/task/005-remove-legacy-paste/plan.md:3` — «Статус: **ожидает G2**», при
том что ADR 0003 `accepted` и реализация завершена. `docs/ARCHITECTURE.md` §2
(«Текущее состояние (as-is)») всё ещё описывает удалённые
`PasteController`/`Entity\Paste`/`PasteRepository`; по плану это вынесено в
задачу 008, поэтому не блокирует, но стоит явно пометить в плане/roadmap.

**N6 (остаётся, неблокирующее; принято разработчиком). Сигнатура
`PasteSchemaAnchor` расходится с фактическим DDL.**
`src/.../Schema/PasteSchemaAnchor.php:33-36` — `?DateTimeImmutable $createdAt = null`
и `$expiresAt = null`, тогда как колонки по миграции `NOT NULL`. DDL не меняется
(проверено, см. «Соответствие plan.md»), но класс читается как «nullable».
Предложение: если дефолты нужны только для PHPStan, добавить поясняющий
комментарий «why» либо `@phpstan-ignore`, а не nullable-тип, чтобы не создавать
иллюзию nullable-колонок.

**N7 (остаётся, неблокирующее). `LegacyRemovalTest` размещён в
`tests/Functional`, хотя это kernel-less структурный guard.**
Локализация: `tests/Functional/LegacyRemovalTest.php:10`. CONVENTIONS §7
предполагает `Functional` для HTTP-сценариев. Не блокирует; при желании —
`tests/Unit` или отдельный `tests/Architecture`.

**N8 (новое, неблокирующее). Сниппет плана расходится с кодом после фикса B1.**
`docs/task/005-remove-legacy-paste/plan.md:120` по-прежнему показывает
`final class PasteSchemaAnchor`, хотя код уже non-final; `plan.md:3` — «ожидает
G2», `plan.md:6` — «ADR 0003 (`proposed`)», тогда как ADR 0003 уже `accepted`.
Это остаток первого прохода; на код не влияет, но эталон нужно привести в
соответствие, чтобы не воспроизвести B1 в будущем.

## Протечки абстракций между слоями

Протечек не найдено:
- `SecretCreatedController` (`Presentation`) не импортирует `Infrastructure`;
  использует только порт `SecretRepository`. Сборка — через DI.
- `PasteSchemaAnchor` (`Infrastructure`) не проникает в `Domain`/`Application`.
- `Domain`/`Application` не импортируют Symfony/Doctrine/Sodium (файлы не
  менялись).
- `Kernel`/`Schedule`/`EventSubscriber` не ссылаются на классы `Secret`/`Crypto`
  (Schedule использует имя команды строкой), поэтому целевой ruleset deptrac
  достижим.

## Тестовое покрытие

- Добавлены: `SecretCreatedFlowTest` (AC1–AC3, AC6, AC11), `PasteSchemaAnchorTest`
  (AC8), `LegacyRemovalTest` (AC4), route-тест в `SecretWiringTest` (AC1/AC7).
- Переписаны без смены наблюдаемых ожиданий: `PasteFlowTest`,
  `CreateSecretFlowTest`, `RevealSecretFlowTest`, `DoctrineSecretRepositoryTest`,
  `SodiumCryptographyTest`, `PurgeExpiredSecretsCommandTest`. Негативные
  `class_exists` — только в `LegacyRemovalTest` и
  `PurgeExpiredSecretsCommandTest`.
- `LegacyInteropTest` и `EncryptionServiceTest` удалены (Q4-A).
- `LegacyRemovalTest` не зависит от kernel и от порядка тестов.
- Ожидания статусов/текстов/URL/`no-store`/лимитов в существующих сценариях не
  менялись; единственное явное удаление кейса — пустой plaintext (N3),
  задокументировано в `plan.md`.
- Слабости: N2 (тавтологичность anchor-теста), N3 (нет интеграционного
  покрытия empty-plaintext), N4 (AC2 без проверки нейтральности).

## Замечания по безопасности

- `created` отдаёт только `expiresAt` и URL; `hint`/`plaintext`/`salt`/`nonce`/
  `ciphertext` не рендерятся (leak-тесты в `SecretCreatedFlowTest:90-130`).
- CSRF, rate limiting, `Cache-Control: no-store`, проверка срока Reveal не
  ослаблены; соответствующие конфиги/код не менялись.
- Логирование секретов/ключей не добавлялось.
- `.env.local` не добавлен; удалён только test-only `config/services_test.yaml`.
- Влияния B1 на безопасность не было; сам B1 устранён.

## Итог

- Блокирующих замечаний: **0** (B1 устранён).
- Неблокирующих: **8** (N1 закрыт; N2–N8 остаются предложениями).
- Вердикт: **approved**. Блокер B1 снят: `final` убран у ORM-якоря, prod
  `cache:warmup` подтверждён, mapping/DDL/миграции не затронуты. Оставшиеся
  пункты N2–N8 не препятствуют гейту G3.
- Готовность к G3: **да** (человек запускает `make qa`/`make coverage` и
  коммитит). Рекомендация к задаче 008/уборке: актуализировать
  `plan.md:3,6,120` (N8) и при желании закрыть N2–N7.

## Переоценка N1–N8 (повторное ревью)

| Пункт | Было | Стало |
|---|---|---|
| N1 — пустые заглушки `src/{Controller,Entity,Repository}` и `src/Command` | неблокирующее | **закрыт** (удалены) |
| N2 — тавтологичность `PasteSchemaAnchorTest` | неблокирующее | остаётся, неблокирующее |
| N3 — потеря integration-покрытия пустого plaintext | неблокирующее | остаётся, неблокирующее |
| N4 — AC2 без проверки нейтральности тела | неблокирующее | остаётся, неблокирующее |
| N5 — устаревший статус `plan.md`/`ARCHITECTURE.md` §2 | неблокирующее | остаётся, неблокирующее |
| N6 — nullable-сигнатура якоря при `NOT NULL` DDL | неблокирующее | остаётся, принято разработчиком (DDL не меняется) |
| N7 — `LegacyRemovalTest` в `tests/Functional` | неблокирующее | остаётся, неблокирующее |
| N8 — сниппет `plan.md:120` всё ещё `final` (новое) | — | неблокирующее |
