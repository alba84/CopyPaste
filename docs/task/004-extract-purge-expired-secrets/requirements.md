# 004-extract-purge-expired-secrets: Извлечение сценария удаления истёкших `Secret` в модуль `Secret`

> Статус: **G1 пройден.** Решения Q1–Q6 утверждены человеком (все
> рекомендации A). Поведение неизменно; расписание и условие удаления
> сохраняются.

## Контекст и проблема

Задачи 002 и 003 извлекли в целевой модуль `Secret` сценарии создания
(`CreateSecret`) и выдачи (`RevealSecret`). Сценарий физического удаления
истёкших записей остался целиком на легаси-классах:

- команда `App\Command\PurgeExpiredPastesCommand` (имя `app:paste:purge`)
  инжектит Doctrine ORM-репозиторий `App\Repository\PasteRepository` и
  `Doctrine\DBAL\Connection`, выполняет SQLite-`PRAGMA` и печатает счётчик;
- удаление выражено методом `PasteRepository::purgeExpired()` через ORM
  QueryBuilder (`App\Entity\Paste`);
- запуск по расписанию — `App\Schedule` (`#[AsSchedule('default')]`, cron
  `0 * * * *`, `RunCommandMessage('app:paste:purge')`).

`ROADMAP.md` (пункт 004) и `docs/ARCHITECTURE.md` §9.3 требуют вытеснить этот
сценарий use case'ом `PurgeExpiredSecrets` в `src/Secret/Application`, а
консольный адаптер — в `src/Secret/Presentation/Console`, **не меняя
наблюдаемое поведение** (имя и запуск команды, эффект). Домен и Application не
должны знать Doctrine/Symfony, SQL и SQLite-специфика не должны выходить за
пределы `Infrastructure` (§3.3, §5). Дополнительная сложность: SQLite-обслуживание
(`PRAGMA journal_mode=WAL`, `busy_timeout`, `incremental_vacuum`) сейчас живёт
в консольной команде, т.е. в Presentation — переносить его в целевой
`Secret/Presentation/Console` без выноса нельзя, иначе Presentation начнёт
импортировать Doctrine и `deptrac` придётся ослаблять.

### Фактическое текущее поведение purge (as-is, эталон для 004)

| Аспект | Как сейчас |
|---|---|
| Имя команды | `app:paste:purge` (`#[AsCommand(name: ...)]`) |
| Описание | `Delete expired pastes` |
| Ручной запуск | `php bin/console app:paste:purge` / `make console app:paste:purge` |
| Расписание | `App\Schedule`: `CronExpressionTrigger('0 * * * *')` (раз в час) + `RunCommandMessage('app:paste:purge')`; scheduler-контейнер `messenger:consume scheduler_default --time-limit=3600` (`compose.yaml`, `compose.prod.yaml`) |
| Условие удаления | `expires_at <= now` (`PasteRepository::purgeExpired()`: `DELETE ... WHERE p.expiresAt <= :now`); **точное равенство = удалить** |
| `now` | `new DateTimeImmutable()` (серверное «сейчас») на момент запуска |
| Выборка | Один `DELETE`-запрос, без батчинга; удаляются все подходящие строки |
| Возврат | Число удалённых строк (affected rows) |
| SQLite-обслуживание | Перед удалением: `PRAGMA journal_mode=WAL`, `PRAGMA busy_timeout=5000`; после удаления, **только если `count > 0`**: `PRAGMA incremental_vacuum` |
| Вывод | Ровно `Deleted %d expired paste(s).` (`sprintf`), одна строка |
| Код возврата | `Command::SUCCESS` (0) в любом случае |
| Права/окружение | Специальных прав/переменных нет; работает в том `APP_ENV`, где запущен контейнер; транзакций нет |
| Тесты | **Отсутствуют** (ни unit, ни integration, ни functional/console) |

Вывод: 004 — это «извлечение с сохранением поведения», без изменения схемы БД,
имени команды, расписания и вывода. Переименование команды/таблицы и удаление
прочего легаси — задача 005.

## Пользовательская цель

Как мейнтейнер/разработчик CopyPaste, я хочу, чтобы сценарий удаления истёкших
`Secret` был извлечён в модуль `Secret` по эталону 002/003 (Application use case
+ порт + тонкий консольный адаптер), а наблюдаемое поведение команды
`app:paste:purge` и её расписание остались неизменными, чтобы:

- purge перестал быть единственным сценарием, завязанным на легаси ORM-сущность
  `Paste` и `PasteRepository`, и опирался на порты модуля `Secret`;
- SQL (`DELETE`) и SQLite-обслуживание были изолированы в `Infrastructure`, а не
  в консольной команде;
- граница `expires_at <= now`, счётчик удалённых и точный вывод были
  зафиксированы проверяемыми тестами (сейчас тестов нет);
- переход к задаче 005 (удаление легаси и переименование `paste` → `secret`) не
  упирался в невынесенный purge.

Как оператор/мейнтейнер self-hosted установки, я хочу, чтобы после миграции
ежечасная очистка работала ровно как раньше: та же команда, тот же эффект, тот
же вывод; истёкшие записи удаляются, активные — нет.

## Объём (in scope)

1. **Application use case `PurgeExpiredSecrets`**
   (`src/Secret/Application/PurgeExpiredSecrets`): команда/запрос, обработчик и
   типизированный результат (как минимум — число удалённых). Обработчик берёт
   момент отсечения из `Psr\Clock\ClockInterface` и вызывает порт удаления;
   никакого SQL/HTTP/Doctrine внутри.
2. **Порт удаления истёкших** (форма — решение Q2): метод вида
   `removeExpiredBefore(DateTimeImmutable $now): int`. Реализация — DBAL-адаптер
   в `src/Secret/Infrastructure/Persistence/Doctrine`, выполняющий ровно один
   `DELETE ... WHERE expires_at <= :now`.
3. **SQLite-обслуживание** (`PRAGMA journal_mode=WAL`, `busy_timeout=5000`,
   `incremental_vacuum` при `count > 0`) переносится из команды в
   `Infrastructure` (форма — решение Q3) с сохранением порядка и условия.
4. **Presentation (Console)**: `src/Secret/Presentation/Console/
   PurgeExpiredSecretsCommand` с `#[AsCommand(name: 'app:paste:purge',
   description: 'Delete expired pastes')]`, тонкий: вызвать use case и
   напечатать ровно `Deleted %d expired paste(s).`, вернуть `SUCCESS`.
5. **Расписание**: `App\Schedule` продолжает раз в час отправлять
   `RunCommandMessage('app:paste:purge')`; имя команды и cron не меняются.
   Перенос `Schedule` в модуль — вне объёма 004.
6. **Вытеснение легаси purge**: удаляются `App\Command\PurgeExpiredPastesCommand`
   и ставший мёртвым `PasteRepository::purgeExpired()` (по решению Q5);
   остальной легаси (`PasteRepository`, `App\Entity\Paste`,
   `PasteController::created`, `App\Schedule`) сохраняется до 005.
7. **Тесты**: unit Application (in-memory порт + фиксированные часы: вызов с
   `now`, возврат счётчика, нулевой случай), integration адаптера (граница
   `<=`, прошлые/будущие, идемпотентность), console/functional (имя, описание,
   точный вывод, exit code, эффект в БД). Существующие тесты не меняют ожиданий.
8. **deptrac/качество**: новые классы укладываются в существующие слои
   `Secret_Application`/`Secret_Presentation`/`Secret_Infrastructure`;
   `make qa`, `make coverage`, `doctrine:schema:validate --env=test` зелёные.
9. **Документы задачи**: этот `requirements.md`; обновление `ROADMAP.md` — на
   этапе реализации.

## Вне объёма (out of scope)

- **Переименование команды** `app:paste:purge` → `app:secret:purge` (и/или
  смена описания) — задача 005, отдельный ops-контракт.
- **Переименование таблицы** `paste` → `secret`, миграции данных/схемы —
  задача 005. В 004 миграций нет.
- **Удаление остального легаси**: `PasteController` (`created`/`app_paste_created`),
  `App\Entity\Paste`, `PasteRepository` целиком,
  `UnlockPasteType`/`UnlockPasteData`, `EncryptionService` — задача 005.
- **Перенос/переписывание `App\Schedule`** в модуль `Secret` — вне 004.
- **Контекст `Access`** (rate limiting/квоты) — задача 006.
- **Burn-after-reading** и любые доменные события (`SecretBurned`,
  `SecretExpired`) — не вводятся; нет потребителя (§4.3).
- **Новые контексты** `Identity`, `Audit`, `Notification`, `Attachments`.
- **Изменение крипто-формата**, маршрутов/URL/текстов/заголовков, wire-контракта
  форм, схемы БД, публичного API.
- **Батчинг/лимит на число удаляемых строк** — сейчас один запрос; изменение
  поведения не предлагается.
- **Публикация событий / event bus / eventual consistency** (§4.3).
- **Смена СУБД, CQRS, микросервисы** (§4.3).

## Доменные термины (новые/уточнённые)

**Новых доменных терминов нет.** Задача использует уже зафиксированные
`docs/UBIQUITOUS_LANGUAGE.md` термины: `Secret`, `Expiration`, `Purge`.

- `PurgeExpiredSecrets` (use case), `PurgeExpiredSecretsHandler`,
  `PurgeExpiredSecretsCommand`, `PurgeExpiredSecretsResult`, `ExpiredSecretPurger`
  — **технические** композиции существующих терминов; в глоссарий не
  добавляются.
- Термин `Purge` в глоссарии уже определён как «физическое удаление истёкших
  `Secret`» — уточнения значения не требуется.
- Имя команды `app:paste:purge` **сохраняет легаси-термин `Paste`** как
  наблюдаемый ops-контракт до 005; это осознанное исключение из правила
  «новый код — только `Secret`», зафиксированное Q1.
- Следствие: `docs/UBIQUITOUS_LANGUAGE.md` **не изменяется**. Если на G1 будет
  принято решение Q1-B (переименование команды в 004), это останется
  техническим переименованием и глоссарий всё равно не затрагивает; при
  переименовании таблицы в 005 — отдельное решение.

## Критерии приёмки (Given–When–Then)

**AC1 — Команда: имя, описание, доступность.**
Given удалён легаси `PurgeExpiredPastesCommand` и добавлен
`Secret/Presentation/Console/PurgeExpiredSecretsCommand`,
When выполняется `php bin/console app:paste:purge` (и `make console
app:paste:purge`),
Then команда найдена; имя ровно `app:paste:purge`, описание ровно
`Delete expired pastes`; в списке команд нет второй/дублирующей команды с этим
именем; exit code 0.

**AC2 — Условие удаления совпадает с легаси.**
Given в `paste` есть записи с `expires_at` в прошлом, ровно равным `now` и в
будущем,
When выполняется purge с моментом `now`,
Then удаляются ровно строки с `expires_at <= now` (включая равенство), строки с
`expires_at > now` сохраняются; удаление выполняется одним запросом без
батчинга; число удалённых строк соответствует фактически удалённому.

**AC3 — Точный вывод и код возврата.**
Given purge удалил `N` строк (`N ≥ 0`),
When команда завершается,
Then в stdout ровно одна строка `Deleted N expired paste(s).` (формат
`sprintf`), без лишних строк; код возврата `Command::SUCCESS` (0); при `N = 0`
команда также успешна и печатает `Deleted 0 expired paste(s).`.

**AC4 — SQLite-обслуживание сохранено.**
Given команда запускается на SQLite,
When выполняется purge,
Then на соединении перед удалением выполняются `PRAGMA journal_mode=WAL` и
`PRAGMA busy_timeout=5000`, а `PRAGMA incremental_vacuum` выполняется **только
если удалено больше нуля** — порядок и условие как в легаси; выполнение не
падает при `incremental_vacuum` на схеме без `auto_vacuum=INCREMENTAL`.

**AC5 — Оркестрация в Application, команда тонкая.**
Given `Secret/Presentation/Console/PurgeExpiredSecretsCommand` и Application
`PurgeExpiredSecrets`,
When команда запускается,
Then адаптер только вызывает use case и печатает его результат; в команде нет
SQL/QueryBuilder, `Doctrine\DBAL\Connection`, ORM-сущностей, расчёта `now` и
SQLite-`PRAGMA`.

**AC6 — Момент отсечения — из `Clock`.**
Given use case и `Psr\Clock\ClockInterface`,
When обработчик определяет момент отсечения,
Then используется `Clock->now()`, а не `new DateTimeImmutable()`; при
фиксированных часах граница `expires_at <= now` проверяется детерминированно в
тестах.

**AC7 — SQL изолирован в адаптере порта.**
Given порт удаления и его DBAL-адаптер,
When purge вызывает порт,
Then SQL `DELETE ... WHERE expires_at <= :now` присутствует только в
`src/Secret/Infrastructure`; QueryBuilder/DBAL/ORM не выходят наружу;
`Domain` и `Application` не импортируют Doctrine.

**AC8 — Границы слоёв (deptrac).**
Given `deptrac.yaml`,
When выполняются `make deptrac` и `make qa`,
Then 0 нарушений: `Secret/Application/PurgeExpiredSecrets` не импортирует
Symfony/Doctrine/Sodium; консольная команда в `Secret/Presentation/Console` не
импортирует `Doctrine\*`; существующие правила слоёв не ослабляются (любое
расширение ruleset — отдельное решение G2).

**AC9 — Легаси purge вытеснён, совместимость сохранена.**
Given `App\Command\PurgeExpiredPastesCommand` и
`PasteRepository::purgeExpired()`,
When 004 завершена,
Then легаси-команда удалена; `purgeExpired()` больше не используется и удалена
(по решению Q5); `PasteRepository`, `App\Entity\Paste`,
`PasteController::created`, `App\Schedule` и `templates/paste/*.twig` остаются и
работают до 005.

**AC10 — Расписание неизменно.**
Given `App\Schedule` (`#[AsSchedule('default')]`),
When проверяется расписание,
Then cron `0 * * * *` и `RunCommandMessage('app:paste:purge')` сохранены;
scheduler-контейнер (`messenger:consume scheduler_default`) продолжает
запускать ту же команду; изменение расписания не требуется.

**AC11 — Тестовое покрытие.**
Given новые тесты,
When выполняется `make test`,
Then покрыты: (a) application unit на in-memory порте и `FixedClock` — порт
вызван с `Clock->now()`, возвращён счётчик, отдельно нулевой случай; (b)
integration DBAL-адаптера — границы `expires_at < now` / `== now` / `> now`,
возвращаемое число, повторный запуск даёт 0; (c) console/functional — имя,
описание, точный вывод, exit code, фактическое удаление истёкших и сохранение
активных. Существующие тесты проходят без смены ожиданий.

**AC12 — Безопасность и логи.**
Given purge не работает с содержимым записей,
When проверяются вывод и логи,
Then выводится/логируется только количество удалённых; `token`, `hint`,
`salt`/`nonce`/`ciphertext`, plaintext и ключи не попадают в вывод/логи; CSRF,
rate limiting, `no-store` и проверка срока существующих сценариев не
затрагиваются; схема БД и крипто-формат не меняются.

**AC13 — Качество.**
Given `make qa`, `make coverage`, `doctrine:schema:validate --env=test`,
When они выполняются,
Then все зелёные; покрытие `src/Secret/Domain` и `src/Secret/Application`
≥ 90% (новый код покрыт); PHPStan L10 — 0 ошибок без baseline;
`@phpstan-ignore` — только точечно, с указанием причины и даты (формат 001);
схема БД синхронна, миграций нет.

**AC14 — Регрессия: активные Secret не пострадали.**
Given активные (неистёкшие) `Secret`, созданные/читаемые путями 002/003,
When выполняется purge,
Then они не удаляются и по-прежнему открываются Reveal; истёкшие удаляются;
никакие маршруты, URL, тексты, форматы и публичные контракты не изменены.

## Открытые вопросы (и ответы человека)

> Статус G1: **пройден.** Ниже варианты и рекомендации аналитика, затем
> зафиксированные решения человека (все A).

### Q1. Имя и описание команды: сохранить или переименовать в 004?

Имя `app:paste:purge` и описание `Delete expired pastes` — наблюдаемый
ops-контракт (расписание, документация, привычки оператора), но содержат
легаси-термин `Paste`.

- **A (рекомендую).** Сохранить в 004 ровно `app:paste:purge` и `Delete expired
  pastes`; переименование в `app:secret:purge`/`Delete expired secrets` —
  задача 005 вместе с удалением легаси. Соответствует инварианту «поведение
  неизменно» и не ломает расписание.
- **B.** Переименовать в 004: обновить `#[AsCommand]`, `App\Schedule`, `Makefile`,
  `compose*`, CI, документацию. Меняет ops-контракт; требует синхронного
  обновления тестов/`ROADMAP.md»; противоречит цели 004.
- **C.** Оставить старое имя и добавить алиас `app:secret:purge` (Symfony
  aliases) — новый публичный контракт, лишние тесты; не нужен.

### Q2. Где разместить запрос удаления: расширить `SecretRepository` или отдельный порт?

- **A (рекомендую).** Расширить `SecretRepository` методом
  `removeExpiredBefore(\DateTimeImmutable $now): int`; реализация — существующий
  `DoctrineSecretRepository` (DBAL `DELETE`). Минимум классов, один адаптер
  пишет в `paste`, парность с легаси `PasteRepository::purgeExpired`, не
  противоречит §4.2. Тест-дубль `InMemorySecretRepository` дополняется методом.
- **B.** Отдельный порт `ExpiredSecretPurger` в `Secret/Domain/Repository` +
  адаптер `DoctrineExpiredSecretPurger`. Плюс — `SecretRepository` остаётся
  про CRUD агрегата, тест-дубли 002/003 не меняются; минус — лишние порт,
  адаптер и DI-алиас ради одной операции.
- **C.** Без порта: команда/инфраструктурный сервис обращается к БД напрямую
  (`PasteRepository`/DBAL). Не выполняет цель (вынос в модуль), нарушает
  §3.3/§5; отклоняю.

### Q3. Где выполнять SQLite-обслуживание (`PRAGMA`, `incremental_vacuum`)?

- **A (рекомендую).** Перенести внутрь DBAL-адаптера, реализующего удаление
  (`DoctrineSecretRepository::removeExpiredBefore` по Q2-A либо
  `DoctrineExpiredSecretPurger` по Q2-B): адаптер и так SQLite-специфичен, а
  порядок и условие («vacuum только при `count > 0`») сохраняются дословно.
  Тогда консольная команда не импортирует Doctrine.
- **B.** Перенести в инфраструктурный event subscriber (буквально §5
  ARCHITECTURE). Меняет момент выполнения (на каждом соединении, а не только
  при purge) и вводит новую cross-cutting абстракцию — не сохраняет поведение.
- **C.** Оставить `PRAGMA`/`Connection` в консольной команде. Требует разрешить
  `Doctrine` слою `Secret_Presentation` в `deptrac`, тянет SQLite в
  Presentation; противоречит §3.3; отклоняю.

### Q4. Форма use case и результата?

- **A (рекомендую).** `PurgeExpiredSecrets` (объект-команда без параметров) +
  `PurgeExpiredSecretsHandler` + `PurgeExpiredSecretsResult` с числом удалённых
  (`deletedCount`). Handler зависит от порта и `ClockInterface`. Согласуется с
  эталоном 002/003 и расширяемо (аудит/уведомления позже).
- **B.** `PurgeExpiredSecretsHandler::__invoke(): int` без DTO команды и
  результата. Минимализм; но выбивается из эталона и хуже расширяется.
- **C.** Без Application-слоя (команда вызывает порт напрямую) — §4.2
  допускает для одного вызова, но цель 004 явно называет use case
  `PurgeExpiredSecrets`; отклоняю.

### Q5. Что именно удаляется из легаси в 004?

- **A (рекомендую).** Удалить `App\Command\PurgeExpiredPastesCommand` и
  ставший мёртвым `PasteRepository::purgeExpired()`; остальное
  (`PasteRepository`, `App\Entity\Paste`, `PasteController::created`,
  `App\Schedule`) сохранить до 005. Убирает dead code, не расширяя объём.
- **B.** Оставить `purgeExpired()` до 005 «на всякий случай» — мёртвый код,
  требует поддержки статики/покрытия; не рекомендую.
- **C.** Удалить `PasteRepository` целиком в 004 — вне объёма (нужен
  `created`), срывает график 005; отклоняю.

### Q6. Тесты purge и рост `@phpstan-ignore` (002 Н2 / 003 Н6)?

Сейчас у purge **нет ни одного теста**; к концу 003 счётчик `@phpstan-ignore`
вырос до 15 (все в тестах, `property.uninitialized`, причина системная —
PHPStan не видит инициализацию в `setUp()`).

- **A (рекомендую).** Добавить три уровня тестов (unit application, integration
  адаптера, console/functional); в новых тестах инициализировать свойства в
  конструкторе/локальными переменными, чтобы не увеличивать счётчик ignore;
  системное устранение (`phpstan/phpstan-phpunit` или базовый `TestCase`) —
  отдельная задача.
- **B.** Ограничиться unit + integration, без console-теста. Дешевле, но
  ослабляет защиту AC1/AC3/AC10 (имена/вывод/эффект команды).
- **C.** Заодно внедрить базовый `TestCase` в 004 — расширяет объём и смешивает
  задачи; не рекомендую.

### Решения, утверждённые на G1

| Вопрос | Решение |
|---|---|
| Общее подтверждение объёма | `requirements.md` утверждён как основа задачи 004 |
| Q1 — имя/описание команды | **A.** Сохранить `app:paste:purge` и `Delete expired pastes`; переименование — в 005 |
| Q2 — порт удаления | **A.** `SecretRepository::removeExpiredBefore(DateTimeImmutable): int`; реализация в `DoctrineSecretRepository`; дополнить `InMemorySecretRepository` |
| Q3 — SQLite-обслуживание | **A.** Перенести `PRAGMA`/`incremental_vacuum` (при `count > 0`) внутрь DBAL-адаптера; команда не импортирует Doctrine |
| Q4 — форма use case | **A.** `PurgeExpiredSecrets` + `PurgeExpiredSecretsHandler` + `PurgeExpiredSecretsResult` (`deletedCount`); handler зависит от порта и `ClockInterface` |
| Q5 — объём удаления легаси | **A.** Удалить `PurgeExpiredPastesCommand` и мёртвый `PasteRepository::purgeExpired()`; остальное легаси — до 005 |
| Q6 — тесты и `@phpstan-ignore` | **A.** unit + integration + console; свойства инициализировать без новых ignore; системное устранение — отдельная задача |

## Нефункциональные требования и риски

### Нефункциональные требования

- **Неизменность поведения.** Имя и описание команды, cron-расписание,
  условие `expires_at <= now`, один DELETE, счётчик, точный вывод, exit code и
  SQLite-обслуживание (`WAL`, `busy_timeout`, `incremental_vacuum` при
  `count > 0`) сохраняются. Схема БД и крипто-формат не меняются; миграций нет.
- **Безопасность.** Не логировать и не выводить `token`, `hint`, `salt`/`nonce`/
  `ciphertext`, plaintext и ключи; выводится только количество. Purge не
  ослабляет CSRF/rate limiting/`no-store`/проверку срока существующих
  сценариев; `.env.local` не коммитится.
- **Слои и границы.** Domain/Application без Symfony/Doctrine/Sodium; SQL и
  SQLite-специфика только в `Infrastructure`; Presentation без Doctrine;
  `deptrac` без новых ослаблений (расширение ruleset — через G2).
- **Тестируемость.** `now` — через `ClockInterface`; порт подменяется in-memory
  дублем; адаптер проверяется интеграционно на реальной SQLite-схеме.
- **Совместимость.** PHP 8.4, Symfony 7.4, Doctrine ORM 3 / DBAL, SQLite;
  `doctrine:schema:validate --env=test` зелёный.
- **Качество.** `make qa` (cs-check, PHPStan L10 — 0 ошибок без baseline,
  deptrac 0, tests) и `make coverage` зелёные; покрытие `src/Secret/Domain` и
  `src/Secret/Application` ≥ 90% (`docs/QUALITY.md` §2.4).
- **Обозримость diff.** Изменения только в объёме 004; легаси вне purge не
  переписывается; каждый шаг обратим.

### Риски

| Риск | Влияние | Митигация |
|---|---|---|
| Дублирование имени команды при сосуществовании легаси и нового класса | Symfony падает при регистрации / команда недоступна | Удаление `PurgeExpiredPastesCommand` и добавление нового — атомарно, в одном шаге; AC1 проверяет отсутствие дубля |
| `PRAGMA`/`incremental_vacuum` выполняются не в том порядке/условии | Отличие в поведении/производительности очистки | Q3-A: перенос дословно в адаптер; AC4 |
| Порт удаления невольно расширяет `SecretRepository` и ломает тест-дубли/тесты 002/003 | Регресс create/reveal | Q2-A: дополнить `InMemorySecretRepository`; AC11 «существующие тесты без смены ожиданий» |
| Doctrine/SQLite протекают в Presentation | Нарушение §3.3, ослабление deptrac | Q3-A + AC5/AC8: команда без `Doctrine\*` |
| Сдвиг границы (`<` вместо `<=`) удаляет/оставляет не те строки | Утечка или преждевременное удаление записей | AC2/AC11: тесты границы `== now` |
| Отсутствие тестов у команды оставляет регрессии незамеченными | Сломанный purge в проде | Q6-A: unit + integration + console тесты; AC11 |
| Рост `@phpstan-ignore` в новых тестах | Шум quality-гейта, эскалация 001 | Q6-A: локальные переменные/инициализация; системная правка — отдельная задача |
| Превышение объёма (переименование команды/таблицы, удаление легаси) | Затягивание 004, конфликт с 005 | Явный out of scope; Q1/Q5; ревью |
| `PRAGMA journal_mode=WAL` внутри транзакции | SQLite-ошибка выполнения | Адаптер не открывает транзакцию вокруг purge (как легаси); AC4 |
| Расписание в `App\Schedule` ссылается на строку имени команды | При переименовании тихая поломка cron | Q1-A: имя не меняется; AC10 |

## Definition of Done (для этой задачи)

1. Решения Q1–Q6 зафиксированы человеком на G1; все отклонения от поведения по
   умолчанию оформлены отдельными вопросами.
2. Use case `PurgeExpiredSecrets` и результат лежат в
   `src/Secret/Application/PurgeExpiredSecrets`; порт удаления — в
   `src/Secret/Domain`; адаптер с SQL и SQLite-обслуживанием — в
   `src/Secret/Infrastructure`; консольный адаптер — в
   `src/Secret/Presentation/Console`; Domain/Application без фреймворка,
   команда без Doctrine.
3. Наблюдаемое поведение неизменно: имя `app:paste:purge`, описание
   `Delete expired pastes`, cron `0 * * * *`, `expires_at <= now`, один DELETE,
   точный вывод `Deleted N expired paste(s).`, exit code 0, SQLite-`PRAGMA`/
   условный `incremental_vacuum`.
4. Легаси purge вытеснён: `PurgeExpiredPastesCommand` и
   `PasteRepository::purgeExpired()` удалены (по Q5); `PasteRepository`,
   `App\Entity\Paste`, `PasteController::created`, `App\Schedule` сохранены и
   работают; `Schedule` не меняется.
5. `make qa` зелёный (cs-check, PHPStan L10 — 0 ошибок без baseline, deptrac 0,
   тесты); `make coverage` — покрытие `src/Secret/Domain` и
   `src/Secret/Application` ≥ 90%; `doctrine:schema:validate --env=test`
   зелёный; миграций нет.
6. Purge покрыт тестами: unit Application (порт + `FixedClock`, нулевой случай),
   integration адаптера (граница `<=`, прошлые/будущие, идемпотентность),
   console/functional (имя, описание, вывод, exit code, эффект в БД);
   существующие тесты не меняют ожиданий.
7. `docs/UBIQUITOUS_LANGUAGE.md` не изменён (новых доменных терминов нет);
   легаси-термин `Paste` в имени команды сохранён осознанно (Q1) и не
   проникает в новый PHP-код.
8. Артефакты задачи — в `docs/task/004-extract-purge-expired-secrets/`;
   `ROADMAP.md` актуализирован на этапе реализации.
