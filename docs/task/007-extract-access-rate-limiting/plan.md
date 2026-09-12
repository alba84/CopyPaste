# План: 007-extract-access-rate-limiting

> Статус: **G2 пройден.** Решения G1 (Q1–Q6, все вариант A) обязательны:
> Q1-A — порт `Access/Domain` + адаптер `Access/Infrastructure`, вызов из
> `Secret/Presentation`; Q2-A — «use case» трактуется как пользовательский
> сценарий (формулировка `ROADMAP.md` уточняется в 008); Q3-A — один
> минимальный порт + два именованных лимита; Q4-A — ADR не нужен, добавить слои
> deptrac; Q5-A — тесты порта/адаптера + wiring, существующие functional без
> смены ожиданий; Q6-A — лимиты/ключи/тексты/`422`/порядок сохраняются.
>
> Инвариант: **наблюдаемое поведение лимитов не меняется** (лимиты, политика,
> окно, ключи, тексты, `422`, порядок `consume`, `cache.app`). Любое отклонение —
> отдельный триггер/задача.

## Связь с требованиями

Задача — последний миграционный срез перед 008: механизм rate limiting переезжает
из `Secret/Presentation` в новый bounded context `Access` за framework-free
портом, как предписывает `docs/ARCHITECTURE.md` §3.1/§3.2. `Secret` перестаёт
импортировать `Symfony\Component\RateLimiter\RateLimiterFactory` и знать имена
Symfony-фабрик; граница `Access` начинает проверяться deptrac. Сценарии
`Secret` и их домен/Application не затрагиваются; вызов порта остаётся в
Presentation, потому что только это воспроизводит порядок «`consume` до
валидации» и «`404` до `consume`» (Q1-A/Q2-A).

Решения G1 разворачиваются в плане так:

| G1 | Как реализуется в плане | Шаги |
|---|---|---|
| Q1-A — порт в `Access/Domain`, адаптер в `Access/Infrastructure`, вызов из Presentation | `AccessLimiter` (интерфейс) + `SymfonyAccessLimiter`; контроллеры инжектят порт и вызывают `consume` в прежней точке/порядке | Ш1–Ш3 |
| Q2-A — «use case» = пользовательский сценарий | Вызов остаётся в `Secret/Presentation`; `Secret/Application` не знает о лимитах; правка формулировки `ROADMAP.md` — в 008 | Ш3 (реализация), вне 007 (roadmap-текст) |
| Q3-A — один минимальный порт + два именованных лимита | `consume(AccessLimit, string): AccessDecision`; `create_paste`/`unlock_paste` остаются фабриками Symfony; маппинг лимит→фабрика — в адаптере | Ш1, Ш2 |
| Q4-A — ADR не нужен, deptrac расширить | Слои `Access_Domain`/`Access_Infrastructure`, правило `Secret_Presentation → Access_Domain`; синхронизация `docs/QUALITY.md` §2.2 | Ш1, Ш5 |
| Q5-A — тесты порта/адаптера + wiring, functional без смены ожиданий | `SymfonyAccessLimiterTest`, `AccessWiringTest`, structural guard; существующие functional не трогаются | Ш2, Ш4 |
| Q6-A — лимиты/ключи/тексты/`422`/порядок сохранить | `rate_limiter.yaml` без изменений; ключи и тексты формирует Presentation; вызов на прежнем месте | Ш2, Ш3, Ш4 |

Соответствие критериям приёмки:

| AC | Где закрывается |
|---|---|
| AC1 — контекст `Access` выделен, `Symfony` только в `Access/Infrastructure` | Ш1 (`Access/Domain`), Ш2 (адаптер), Ш3 (Presentation без `RateLimiterFactory`), Ш4 (structural guard), deptrac |
| AC2 — Create-лимит и момент `consume` неизменны | Ш3 (порядок в контроллере), существующий `CreateSecretFlowTest` без правок; Ш2 (unit: 10 accepted / 11 rejected) |
| AC3 — Reveal-лимит, ключ `ip:token` и момент `consume` неизменны | Ш3 (ключ/порядок), существующий `RevealSecretFlowTest` без правок; Ш2 (unit: независимость ключей/лимитов) |
| AC4 — порядок проверок сохранён | Ш3 (код), существующие functional (`404` до лимита, лимит до валидации, расшифровка не вызывается) |
| AC5 — лимиты/политика/хранилище не изменены | `rate_limiter.yaml` не меняется; Ш2 (DI-передача фабрик), Ш4 (wiring-тест конфигурации) |
| AC6 — HTTP-контракт, тексты и `422` неизменны | Ш3 (тексты/статус не меняются), существующие functional без смены ожиданий; `429`/`Retry-After` не вводятся |
| AC7 — границы deptrac обновлены и зелёные | Ш1 (`deptrac.yaml`), `make deptrac` в каждом шаге |
| AC8 — тесты обновлены без смены поведения | Ш2 (unit адаптера), Ш4 (wiring + guard), `make test`/`make coverage` без регрессий |
| AC9 — безопасность и обратимость | `Access` получает только строковый ключ и дискриминатор лимита; нет миграций и `.env.local`; `git revert` по шагам |
| AC10 — документы и трассируемость | Ш5 (`docs/QUALITY.md` §2.2, `ROADMAP.md`); `requirements/plan/review` на месте; `UBIQUITOUS_LANGUAGE.md` не меняется; ADR не требуется |

## Затрагиваемые модули и слои

Создаётся новый контекст `Access`; меняются два контроллера Presentation,
DI-конфиг, deptrac и тесты. `Secret/{Domain,Application}` не трогаются.

| Место | Изменение |
|---|---|
| `src/Access/Domain/AccessLimiter.php` | **новый**: framework-free порт |
| `src/Access/Domain/AccessLimit.php` | **новый**: enum — какой именованный лимит расходуется |
| `src/Access/Domain/AccessDecision.php` | **новый**: enum — результат `Accepted`/`Rejected` |
| `src/Access/Infrastructure/Symfony/SymfonyAccessLimiter.php` | **новый**: адаптер поверх `RateLimiterFactory` |
| `src/Secret/Presentation/Http/CreateSecretController.php` | инжект `AccessLimiter`, вызов на прежнем месте; импорт `RateLimiterFactory` удаляется |
| `src/Secret/Presentation/Http/RevealSecretController.php` | то же для `unlock_paste`; ключ и текст без изменений |
| `config/services.yaml` | исключение `../src/Access/Domain/` из авторегистрации; алиас порта; явные аргументы адаптера; удаление bindings `$createPasteLimiter`/`$unlockPasteLimiter` |
| `config/packages/rate_limiter.yaml` | **без изменений** (единственный источник лимитов 10/1 мин, `fixed_window`, `cache.app`) |
| `deptrac.yaml` | слои `Access_Domain`/`Access_Infrastructure`, правило `Secret_Presentation → Access_Domain` |
| `docs/QUALITY.md` §2.2 | **отдельный docs-коммит** после G2: зеркалит `deptrac.yaml` |
| `docs/task/ROADMAP.md` | статус/журнал 007 (docs-коммит) |
| `tests/Unit/Access/Infrastructure/SymfonyAccessLimiterTest.php` | **новый**: unit адаптера на `InMemoryStorage` |
| `tests/Unit/Access/Architecture/RateLimiterBoundaryTest.php` | **новый**: structural guard |
| `tests/Functional/AccessWiringTest.php` | **новый**: wiring порта и конфигурации лимитов |
| `tests/Support/Access/RecordingAccessLimiter.php` | **новый** тест-двойник порта (см. тест-план) |
| `src/Secret/Domain`, `src/Secret/Application`, `templates/`, `migrations/` | **не трогаются** |

### Проектируемая структура `Access`

```text
src/Access/
├── Domain/
│   ├── AccessLimiter.php        # порт (интерфейс)
│   ├── AccessLimit.php          # enum: CreateSecret | RevealSecret
│   └── AccessDecision.php       # enum: Accepted | Rejected
└── Infrastructure/
    └── Symfony/
        └── SymfonyAccessLimiter.php
```

`Access` не импортирует `Secret`/`Crypto`; `Access/Domain` — только PHP.
`Symfony\Component\RateLimiter` живёт исключительно в
`Access/Infrastructure/Symfony`.

## Изменение модели/контрактов (агрегаты, VO, порты)

Агрегаты, VO, доменные события `Secret` не меняются. Меняется/появляется только
порт лимитирования и его адаптер.

### Порт `AccessLimiter` (`Access/Domain`)

```php
namespace App\Access\Domain;

interface AccessLimiter
{
    public function consume(AccessLimit $limit, string $key): AccessDecision;
}
```

Дискриминатор лимита и результат — отдельные enum'ы без поведения и исполняемых
statements (не портят покрытие `src/*/Domain`):

```php
namespace App\Access\Domain;

enum AccessLimit
{
    case CreateSecret;
    case RevealSecret;
}
```

```php
namespace App\Access\Domain;

enum AccessDecision
{
    case Accepted;
    case Rejected;
}
```

Почему так:

- `AccessLimit` не несёт строковых значений `create_paste`/`unlock_paste`:
  имена Symfony-фабрик остаются деталью инфраструктуры, а домен/код не
  используют легаси-термин `Paste` (UBIQUITOUS_LANGUAGE, правило 2). Маппинг
  `AccessLimit → фабрика` локализован в адаптере.
- `AccessDecision` — типизированный результат вместо «голого» `bool`/`null`
  (`docs/ARCHITECTURE.md` §6, `docs/CONVENTIONS.md` §6); он же точка расширения
  для будущих квот/`Identity` без смены сигнатуры порта.
- Ключ и текст ошибки формирует Presentation; `Access` получает только
  непрозрачную строку ключа и дискриминатор. Никакие `plaintext`, `SecretKey`,
  производные ключи, `salt/nonce` в `Access` не попадают.

### Адаптер `SymfonyAccessLimiter` (`Access/Infrastructure/Symfony`)

```php
namespace App\Access\Infrastructure\Symfony;

use App\Access\Domain\AccessDecision;
use App\Access\Domain\AccessLimit;
use App\Access\Domain\AccessLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class SymfonyAccessLimiter implements AccessLimiter
{
    public function __construct(
        private RateLimiterFactory $createSecretLimiter,
        private RateLimiterFactory $revealSecretLimiter,
    ) {
    }

    public function consume(AccessLimit $limit, string $key): AccessDecision
    {
        $accepted = $this->factoryFor($limit)->create($key)->consume()->isAccepted();

        return $accepted ? AccessDecision::Accepted : AccessDecision::Rejected;
    }

    private function factoryFor(AccessLimit $limit): RateLimiterFactory
    {
        return match ($limit) {
            AccessLimit::CreateSecret => $this->createSecretLimiter,
            AccessLimit::RevealSecret => $this->revealSecretLimiter,
        };
    }
}
```

Вызов `create($key)->consume()->isAccepted()` дословно повторяет текущий код
(включая `consume()` без явного числа токенов), поэтому расход, окно и момент
потребления не меняются.

### Интеграция в Presentation (та же точка и порядок)

`CreateSecretController` — `consume` **до** `isValid()`:

```php
use App\Access\Domain\AccessDecision;
use App\Access\Domain\AccessLimit;
use App\Access\Domain\AccessLimiter;
// ...
public function __invoke(
    Request $request,
    CreateSecretHandler $createSecretHandler,
    AccessLimiter $accessLimiter,
): Response {
    // ...
    if ($form->isSubmitted()) {
        $ip = $request->getClientIp() ?? 'unknown';
        if ($accessLimiter->consume(AccessLimit::CreateSecret, $ip) === AccessDecision::Rejected) {
            $form->addError(new FormError('Слишком много запросов. Попробуйте через минуту.'));
        } elseif ($form->isValid()) {
            // без изменений
        }
    }
```

`RevealSecretController` — гейт `LoadRevealSecret → 404` остаётся **до** формы и
лимита; `consume` — **после `404`**, **до** `isValid()`; ключ `(ip ?? 'unknown').':'.$token`:

```php
if ($form->isSubmitted()) {
    $key = ($request->getClientIp() ?? 'unknown').':'.$token;
    if ($accessLimiter->consume(AccessLimit::RevealSecret, $key) === AccessDecision::Rejected) {
        $form->addError(new FormError('Слишком много попыток. Попробуйте через минуту.'));
    } elseif ($form->isValid()) {
        // без изменений
    }
}
```

Тексты, ветвление `elseif`, `getClientIp() ?? 'unknown'`, статус `422` (ставит
штатный `AbstractController::doRender()`) не меняются.

### DI / конфигурация

`config/packages/rate_limiter.yaml` не меняется. В `config/services.yaml`:

- из `_defaults.bind` удаляются `$createPasteLimiter`/`$unlockPasteLimiter`
  (последние потребители — оба контроллера, после переключения их нет);
- в `App\:` exclude добавляется `- '../src/Access/Domain/'` (как `Crypto/Domain/`);
- добавляются алиас порта и явные аргументы адаптера:

```yaml
    App\Access\Domain\AccessLimiter: '@App\Access\Infrastructure\Symfony\SymfonyAccessLimiter'

    App\Access\Infrastructure\Symfony\SymfonyAccessLimiter:
        arguments:
            $createSecretLimiter: '@limiter.create_paste'
            $revealSecretLimiter: '@limiter.unlock_paste'
```

Явные аргументы обязательны: `RateLimiterFactory` — конкретный класс с
несколькими однотипными сервисами, автовайринг по типу неоднозначен. Значения
лимитов остаются единственным источником в `rate_limiter.yaml`; в
`Access/Domain` и в env они не дублируются.

### deptrac

```yaml
    # layers
    - { name: Access_Domain,         collectors: [{ type: classNameRegex, value: '#^App\\Access\\Domain\\#' }] }
    - { name: Access_Infrastructure, collectors: [{ type: classNameRegex, value: '#^App\\Access\\Infrastructure\\#' }] }
    # ruleset
    Access_Domain: []
    Access_Infrastructure: [Access_Domain, Symfony, Shared]
    Secret_Presentation: [Secret_Application, Secret_Domain, Symfony, Psr, Access_Domain]
```

Остальные правила не меняются. Отсутствие `Access_*` в правилах
`Secret_Domain`/`Secret_Application` делает зависимости `Secret_Domain`/
`Secret_Application → Access_*` нарушением (deptrac запрещает неразрешённое), а
`Secret_Presentation → Access_Infrastructure` запрещено тем, что разрешён только
`Access_Domain`. Так закрывается AC7.

## Тест-план

Существующие functional-тесты `CreateSecretFlowTest`, `RevealSecretFlowTest`,
`PasteFlowTest` **не меняются** (ни ожиданий, ни imports) — это главный
регрессионный барьер. Технические правки не требуются: тесты работают через
HTTP и `cache.app`, не обращаясь к лимитер-классам.

- **unit:**
  - `tests/Unit/Access/Infrastructure/SymfonyAccessLimiterTest.php` — на двух
    вручную собранных `RateLimiterFactory` с `InMemoryStorage` (детерминированно,
    без HTTP и файлового кеша):
    - `fixed_window`, limit 10, interval `1 minute`: 10 `consume` для
      `AccessLimit::CreateSecret`/key `ip` → `Accepted`, 11-й → `Rejected`;
    - то же для `AccessLimit::RevealSecret`/key `ip:token`;
    - разные ключи независимы: исчерпание `key-a` не влияет на `key-b`;
    - разные лимиты независимы: исчерпание `CreateSecret` не влияет на
      `RevealSecret` при том же ключе;
    - маппинг проверяется разными limit'ами (create 10, reveal 1): 1-й
      `RevealSecret` принят, 2-й отклонён, при этом `CreateSecret` ещё принят.
    - Вспомогательные `factory(string $id, int $limit)` держат конфиг в тесте;
      это независимая от `rate_limiter.yaml` проверка семантики адаптера.
    - **Тест-двойник порта:** `tests/Support/Access/RecordingAccessLimiter`
      (implements `AccessLimiter`; записывает пары `(AccessLimit, key)` и
      возвращает заданный `AccessDecision`). Используется в опциональном
      Presentation-тесте Q5-B (фиксация «consume до валидации» без HTTP); если
      Q5-B не реализуется, файл не создаётся, чтобы не держать мёртвый код
      (AC8 его не требует — порядок покрыт существующими functional).
  - `tests/Unit/Access/Architecture/RateLimiterBoundaryTest.php` — структурный
    guard по файлам `src/**/*.php`:
    - `Symfony\Component\RateLimiter` встречается только под
      `src/Access/Infrastructure/`;
    - в `src/Access/Domain/` нет `Symfony\`/`Doctrine\`/`sodium_`;
    - `src/Secret/{Domain,Application,Presentation}` не ссылается на
      `App\Access\Infrastructure` (и `Secret/Domain`/`Application` — на `App\Access`).
- **integration:**
  - Отдельного интеграционного теста адаптера не требуется: `InMemoryStorage`
    даёт детерминированную семантику, а реальная связка
    `rate_limiter.yaml → адаптер → cache.app` покрывается wiring-тестом и
    существующими functional (10/11 через HTTP).
- **application:**
  - **Нет.** `Secret/Application` не зависит от `Access`; use case'ы не меняются,
    их покрытие сохраняется.
- **functional:**
  - `tests/Functional/AccessWiringTest.php` (`KernelTestCase`):
    - контейнер резолвит `App\Access\Domain\AccessLimiter` в
      `SymfonyAccessLimiter` (порт → адаптер);
    - `config/packages/rate_limiter.yaml` через `Symfony\Component\Yaml\Yaml`
      содержит ровно `create_paste` и `unlock_paste` с `policy: fixed_window`,
      `limit: 10`, `interval: '1 minute'`, `cache_pool: cache.app` (без
      дублирования env; AC5);
    - опционально — поведенческая проверка реального конфига: `cache.app->clear()`
      и 10 `Accepted`/11-й `Rejected` на уникальном ключе. Если окажется
      флейкой — остаётся только проверка конфига и port→adapter, поведение уже
      доказано существующими functional.
  - Существующие `CreateSecretFlowTest`, `RevealSecretFlowTest`, `PasteFlowTest`
    — **без изменений**; `SecretWiringTest` — без изменений (дополнение DI
    проверяется новым `AccessWiringTest`).

## Пошаговый план реализации

Каждый шаг — отдельный обратимый коммит; после каждого `make qa` (cs-check,
PHPStan L10, deptrac, PHPUnit) и `make coverage` зелёные. Миграций нет.

- **Ш0. Baseline (без коммита).** `make up` (при необходимости), `make qa`,
  `make coverage`, `make console doctrine:schema:validate --env=test`.
  Зафиксировать текущий результат и покрытие. Убедиться, что `vendor` содержит
  `symfony/rate-limiter` 7.4.

- **Ш1. Контекст `Access/Domain` и границы deptrac (AC1, AC7 seed).**
  Создать `AccessLimiter`, `AccessLimit`, `AccessDecision`; добавить в
  `deptrac.yaml` слои и правила; в `config/services.yaml` исключить
  `../src/Access/Domain/`. Порт ещё никем не используется — поведение не
  меняется. Коммит: `feat: add Access domain rate limiting port`.

- **Ш2. Symfony-адаптер и DI (AC1, AC3, AC5; unit AC8).**
  Добавить `SymfonyAccessLimiter`; в `config/services.yaml` — алиас порта и
  явные аргументы `$createSecretLimiter`/`$revealSecretLimiter` (bindings
  `$createPasteLimiter`/`$unlockPasteLimiter` пока остаются, ими ещё пользуются
  контроллеры); добавить `SymfonyAccessLimiterTest`. Коммит:
  `feat: add Symfony rate limiter adapter for Access port`.

- **Ш3. Переключение Presentation на порт (AC1–AC4, AC6).**
  `CreateSecretController`/`RevealSecretController` инжектят `AccessLimiter`,
  вызывают `consume` в прежней точке/порядке; удалить импорт
  `RateLimiterFactory`; удалить неиспользуемые bindings
  `$createPasteLimiter`/`$unlockPasteLimiter`. Прогнать `make test` —
  существующие functional зелёные **без изменения ожиданий**. Коммит:
  `refactor: consume rate limits through Access port`.

- **Ш4. Wiring и structural guard (AC8, AC7).**
  Добавить `AccessWiringTest` (порт → адаптер; значения `rate_limiter.yaml`) и
  `RateLimiterBoundaryTest`. При реализации опционального Q5-B — добавить
  `RecordingAccessLimiter` и Presentation-order-тест. Коммит:
  `test: cover Access rate limiting wiring and boundaries`.

- **Ш5. Документы (AC10).** После G2 обновить `docs/QUALITY.md` §2.2 (зеркало
  `deptrac.yaml`), `docs/task/ROADMAP.md` (статус/журнал 007);
  `docs/UBIQUITOUS_LANGUAGE.md` не менять; ADR не создавать. Коммит:
  `docs: record Access rate limiting context and deptrac rules`.

- **Ш6. Финальная верификация (без коммита).** `make qa`, `make coverage`
  (Domain/Application ≥ 90%), `make console doctrine:schema:validate --env=test`;
  `grep`-проверка, что `Symfony\Component\RateLimiter` не встречается вне
  `Access/Infrastructure` и что `config/packages/rate_limiter.yaml` не изменён;
  передать на G3.

## Миграции БД

**Нет.** Схема, данные, `secret`, TTL и крипто-формат не затрагиваются. Rate
limiting живёт в `cache.app`; миграций и изменений `doctrine.yaml` нет.

## Влияние на стандарты (ADR, если нужно)

**ADR не нужен** (Q4-A). Создание контекста `Access` и его порта — прямое
исполнение `docs/ARCHITECTURE.md` §3.1/§3.2; расширение deptrac-набора —
исполнение §3.3, а не изменение архитектурного стандарта. `docs/QUALITY.md` §2.2
называет ruleset ориентиром и уточняется при появлении модулей — синхронизация
делается docs-коммитом Ш5, без ADR. `docs/ARCHITECTURE.md` не меняется (его
актуализация — задача 008).

Если в ходе G2 выяснится необходимость изменить стандарт (например, перенести
вызов в Application или изменить политику лимитов) — это отдельный ADR и гейт G2
с решением человека; в рамках 007 такой триггер не предполагается.

## Риски и обратимые шаги

| Риск | Влияние | Мера | Обратимость |
|---|---|---|---|
| Смена порядка `consume` относительно `404`/валидации | Иное наблюдаемое поведение, ослабление защиты | Q1-A: вызов в Presentation на прежнем месте; существующие functional без смены ожиданий; AC4 | `git revert` Ш3 |
| Изменение ключа (`ip` / `ip:token`) или текста | Регресс лимитов/UX | Точные ключи/тексты сохраняются в контроллерах; AC2/AC3/AC6; functional | `git revert` Ш3 |
| Протечка `Symfony\Component\RateLimiter` в `Secret`/`Access/Domain` | Нарушение границ, непроверяемость | deptrac `Access_Domain: []`; structural guard; Symfony только в `Access/Infrastructure` | `git revert` Ш1/Ш2 |
| Неоднозначный автовайринг `RateLimiterFactory` | Контейнер не собирается | Явные аргументы `$createSecretLimiter`/`$revealSecretLimiter` в `services.yaml`; wiring-тест | Правка `services.yaml` |
| Удаление bindings ломает старых потребителей | Ошибка сборки контейнера | Удаление bindings — в одном коммите с переключением контроллеров (Ш3); `make test` в том же шаге | `git revert` Ш3 |
| Флейки лимитера из-за общего окна/кеша | Нестабильный `make qa` | unit-тест адаптера на `InMemoryStorage`; functional изолируют `cache.app->clear()`; wiring-поведение — на уникальном ключе (или только проверка конфига) | Локально |
| Падение покрытия `src/*/Domain` | Красный `make coverage` | `Access/Domain` — интерфейс/enum без исполняемых statements; `check-coverage.php` не штрафует; адаптер покрыт unit'ом | — |
| Дублирование значений лимитов в env/коде | Дрейф конфигурации | Единственный источник — `rate_limiter.yaml`; в `Access` значений нет; AC5 | — |
| Слишком «толстый» `Access` (VO/политики) | Преждевременная сложность (§4.2) | Минимум: порт + два enum + адаптер; никаких политик/квот | Упрощение |
| Устаревшая формулировка `ROADMAP.md` «use case'ы» | Расхождение документов и кода | Q2-A: трактуем как пользовательский сценарий; уточнение — в 008; решение зафиксировано здесь | Docs 008 |
| Утечка секретов в `Access`/логи | Нарушение безопасности | Через порт идут только строковый ключ и дискриминатор; логирования нет; `.env.local` не добавляется | — |

## Definition of Done для задачи

- [ ] Решения G1 (Q1–Q6 = A) реализованы; наблюдаемое поведение лимитов не
      изменилось (AC2–AC6), `429`/`Retry-After` не введены (AC6).
- [ ] Создан `src/Access/Domain` (framework-free `AccessLimiter`, `AccessLimit`,
      `AccessDecision`) и `src/Access/Infrastructure/Symfony/SymfonyAccessLimiter`;
      `Secret/Presentation` не импортирует `RateLimiterFactory` (AC1).
- [ ] `Symfony\Component\RateLimiter` встречается только в
      `Access/Infrastructure`; `Secret/Domain`/`Secret/Application` не зависят от
      `Access_*`; structural guard зелёный (AC1, AC7).
- [ ] `config/packages/rate_limiter.yaml` содержит ровно `create_paste` и
      `unlock_paste` (`fixed_window`, `limit: 10`, `interval: '1 minute'`,
      `cache_pool: cache.app`); порт резолвится в адаптер; значения не
      дублируются env/кодом (AC5).
- [ ] `deptrac.yaml` дополнен `Access_Domain`/`Access_Infrastructure` и правилом
      `Secret_Presentation → Access_Domain`; `make deptrac` — 0 нарушений (AC7).
- [ ] Есть unit-тесты адаптера (10 accepted / 11 rejected; независимость ключей
      и лимитов) и wiring-тест (порт → адаптер; конфигурация лимитов);
      существующие functional/wiring-тесты зелёные **без смены ожиданий**;
      `make qa` и `make coverage` (Domain/Application ≥ 90%) зелёные; новых
      `@phpstan-ignore` без причины/даты нет (AC8).
- [ ] Секреты/payload/ключи не попадают в `Access` и логи; CSRF, rate limiting,
      `no-store`, проверка срока не ослаблены; `.env.local` не добавлен;
      миграций нет; изменение локально и обратимо (AC9).
- [ ] `docs/QUALITY.md` §2.2 синхронизирован, `ROADMAP.md` актуализирован,
      `docs/UBIQUITOUS_LANGUAGE.md` не изменён, ADR не создавался (AC10).
- [ ] Артефакты — в `docs/task/007-extract-access-rate-limiting/`.
