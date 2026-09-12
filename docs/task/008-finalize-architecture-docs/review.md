# Ревью: 008-finalize-architecture-docs

## Вердикт: approved

Docs-only задача финализации миграции 002–007. Сверены `docs/ARCHITECTURE.md`
§2/§9/§3.1, `docs/UBIQUITOUS_LANGUAGE.md`, `docs/task/ROADMAP.md` и
`docs/task/008-finalize-architecture-docs/notes.md` с фактической структурой
`src/`, `config/`, `templates/`, `migrations/` и `deptrac.yaml`. Ключевые
утверждения о текущем состоянии подтверждены. Блокирующих замечаний нет;
неблокирующие — ниже.

## Методика и ограничения

- Проверены рабочий diff (uncommitted), `notes.md`, `docs/ARCHITECTURE.md`,
  `docs/UBIQUITOUS_LANGUAGE.md`, `docs/task/ROADMAP.md`, `docs/QUALITY.md`,
  `deptrac.yaml`, `migrations/Version20260910000000.php`, `config/**`,
  `src/**`, `tests/**`, `templates/**`, `compose*.yaml`, `phpstan.neon`.
- **Ограничение среды:** `vendor/` и Docker недоступны, поэтому `make qa`
  (cs-check, PHPStan L10, deptrac, PHPUnit) и `make coverage` не запускались.
  Соответствие `deptrac.yaml`, конфигов и кода проверено статически;
  фактический прогон — за гейтом G3.
- `requirements.md` и `plan.md` у задачи 008 отсутствуют (есть только
  `notes.md`) — это допустимо для docs-only финализации, но формальные AC для
  сверки недоступны; проверка велась по постановке задачи и `notes.md`.

## Соответствие фактическому коду

| Утверждение | Факт | Статус |
|---|---|---|
| `Secret/{Domain,Application,Infrastructure,Presentation}` в целевой структуре | Подтверждено: `src/Secret/**`, VO/порты/события/исключения на месте | ✅ |
| Use cases `CreateSecret`, `RevealSecret` (`Revealed\|InvalidKey\|NotFound`), `PurgeExpiredSecrets` | `src/Secret/Application/{CreateSecret,RevealSecret,PurgeExpiredSecrets}` | ✅ |
| `DoctrineSecretRepository`, `SecretRecord`, `SecretRecordMapper`, `SecretSchemaAnchor` | Присутствуют в `Secret/Infrastructure/Persistence/Doctrine` | ✅ |
| `Crypto`: порт `Cryptography` + Sodium-адаптер | `Crypto/Domain/Cryptography.php`, `Crypto/Infrastructure/Sodium/SodiumCryptography.php` | ✅ |
| `Access`: порт `AccessLimiter` + Symfony-адаптер, вызов из `Secret/Presentation` | `Access/Domain/AccessLimiter.php`, `Access/Infrastructure/Symfony/SymfonyAccessLimiter.php`; `CreateSecretController.php:31`, `RevealSecretController.php:45` | ✅ |
| `Kernel`, `Schedule`, `EventSubscriber` — только техслои | `src/Kernel.php`, `src/Schedule.php`, `src/EventSubscriber/SQLitePragmaSubscriber.php` | ✅ |
| `Shared` зарезервирован в deptrac, классов нет | Слой `Shared` в `deptrac.yaml:19,24`; `src/Shared` отсутствует | ✅ |
| Таблица `secret`, миграция `Version20260910000000`, индексы `uniq_secret_token`/`idx_secret_expires_at` | `migrations/Version20260910000000.php:19-23`; `SecretSchemaAnchor.php:12-14` | ✅ |
| Целевой deptrac | `deptrac.yaml:11-32`, совпадает с `docs/QUALITY.md` §2.2 | ✅ |
| Легаси `App\*` удалён | Бизнес-классов `App\{Controller,Entity,Repository,Service,Dto,Form,Command}` нет; остались `Kernel`/`Schedule`/`EventSubscriber` | ✅ (формулировка — см. N4) |
| §3–§7 по существу не изменены | В diff изменены только шапка, §2, таблица §3.1 и §9 | ✅ |

Переходные публичные имена (проверено по коду/конфигу):

- `app:paste:purge` — `src/Schedule.php:20`, `PurgeExpiredSecretsCommand.php:14`;
- маршруты `app_paste_view`/`app_paste_created` — `RevealSecretController.php:26`,
  `SecretCreatedController.php:16`;
- wire-формы `create_paste`/`unlock_paste` и `csrf_token_id` —
  `CreateSecretType.php:28,33`, `RevealSecretType.php:23,28`,
  `config/packages/rate_limiter.yaml:3,8`, `config/services.yaml:32-33`;
- `templates/paste/*` и DOM-id `#paste-url` —
  `templates/paste/{create,view,created,revealed}.html.twig`,
  `templates/paste/created.html.twig:7`;
- том `paste_data` — `compose.prod.yaml:6,35,42`.

Капитализированный `Paste` в `src/` не встречается (grep по `src/`; только
нижнерегистровые legacy-идентификаторы) — новый домен и код используют
`Secret`. Утверждения §2/§9 и раздел «Переходные публичные имена» фактичны.

## Соответствие requirements.md

Файл отсутствует. Постановка (актуализировать §2/§9, глоссарий, backlog)
выполнена; иных формальных критериев для сверки нет.

## Соответствие plan.md

Файл отсутствует. Фактический объём совпадает с `notes.md` «Что сделано»:
изменены `docs/ARCHITECTURE.md` и `docs/UBIQUITOUS_LANGUAGE.md`; ADR не
создавался. Отклонение: `notes.md:13` утверждает, что `ROADMAP.md` «не
трогался», тогда как в рабочем diff он изменён оркестратором (статус 008,
формулировки §007, журнал). На выводы не влияет, но формулировку стоит
уточнить (см. N2).

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- Изменения затрагивают as-is (§2) и стратегию (§9), а не архитектурный
  стандарт (§3–§7). Требование §8 «изменение правила — через ADR» не нарушено:
  правки статуса контекстов в §3.1 — факт, а не норма. Отсутствие ADR
  обосновано в `notes.md:15-18`, согласен.
- `docs/CONVENTIONS.md` не менялся и не противоречит новым формулировкам.
- Терминология новых фрагментов соответствует `UBIQUITOUS_LANGUAGE.md`
  (`Secret`, `Reveal`, `Burn-after-reading`, `Ttl`, `Token`).

## Найденные проблемы

### Блокирующие

Нет.

### Неблокирующие

- **N1 (факт).** `docs/ARCHITECTURE.md:280-282` и
  `docs/task/008-finalize-architecture-docs/notes.md:49`: backlog-пункт
  «вынести `migrations/` из PHPStan `paths`» опирается на неверную предпосылку —
  в `phpstan.neon:8` `paths: [src, tests]`, каталог `migrations/` не
  анализируется. Пункт следует удалить либо переформулировать (например,
  «типизировать миграции, если включать их в анализ»). *Основание: сверка с
  `phpstan.neon`; это backlog, а не стандарт, поэтому не блокирует.*
- **N2 (оркестратор).** `docs/task/ROADMAP.md:43` помечает 008 `[x]`, а
  `ROADMAP.md:149` — «коммит выполнен», хотя изменения не закоммичены
  (`git status`: modified, не staged) и гейт G3 не пройден. По легенде
  `ROADMAP.md:9-10` `[x]` означает «гейт G3 пройден человеком». Нужно
  согласовать статус с реальностью (вернуть `[~]`/убрать «коммит выполнен» до
  G3). *Основание: `ROADMAP.md` §«Обозначения»; правка принадлежит
  оркестратору.*
- **N3 (формулировка).** `docs/UBIQUITOUS_LANGUAGE.md:54-55`: «`Paste` не
  используется нигде, кроме исторических артефактов решений (ADR,
  `docs/task/<id>/`)» — слишком широко. Термин `Paste` сохраняется в
  `tests/Functional/PasteFlowTest.php`, `tests/Integration/Migrations/…`
  (`RenamePasteToSecretMigrationTest`), `docs/tz/1-tz-copypaste.md` и в
  исторических абзацах `docs/ARCHITECTURE.md` §5/§9. Предлагаю уточнить:
  «в новом коде и документации — не используется; вне этого раздела остаётся
  только в исторических/тестовых артефактах». *Основание: точность глоссария;
  на код не влияет.*
- **N4 (формулировка).** `docs/ARCHITECTURE.md:8` («легаси `App\*` удалён»)
  неточно: namespace `App\*` сохранён (`App\Kernel`, `App\Schedule`,
  `App\EventSubscriber`, `App\Secret\*`). §2:28-29 корректно говорит
  «бизнес-легаси `App\*`». Стоит повторить «бизнес-легаси» в шапке.
  *Основание: сверка с `src/`.*
- **N5 (вне объёма, docs).** `docs/QUALITY.md:10,13-16` («Текущее состояние»)
  устарел и противоречит обновлённому `docs/ARCHITECTURE.md:55-57`: PHPStan
  указан level 8 (факт — level 10, `phpstan.neon:7`), deptrac/покрытие/
  composer-QA помечены «Нет» (фактически настроены: `deptrac.yaml`,
  `tools/check-coverage.php`, `Makefile:34-41`, `composer.json:52-61`).
  Раздел не входил в объём 008; рекомендую отдельную docs-правку, иначе
  документы противоречат друг другу. *Основание: сверка с конфигами.*
- **N6 (вне объёма, предложение).** `AGENTS.md:20` описывает `Entity/`,
  `Repository/` и `Service/EncryptionService.php` как текущее место хранения и
  работы с Sodium; в коде этих классов нет (крипто — `Crypto/Infrastructure`,
  персистентность — `Secret/Infrastructure`). Файл вне заявленного списка
  (`docs/`, `src/`, `tests/`, `config/`); рекомендую обновить в follow-up.
  *Основание: точность репозиторных гайдов.*

## Протечки абстракций между слоями

Docs-only изменений кода нет, поэтому новых протечек быть не может. Для
контекста проверено текущее состояние: `src/**/Domain` не импортирует
Symfony/Doctrine/Sodium (grep), `Secret_Presentation` не импортирует
`Access_Infrastructure`, `deptrac.yaml` целевой. Протечек не обнаружено.

## Тестовое покрытие

Изменений `src/`, `tests/`, `templates/`, `config/` нет (`git status`:
только `docs/`). Тестовое покрытие задачи неприменимо. Заявленный в backlog
рост `@phpstan-ignore` подтверждён: 11 вхождений `property.uninitialized` в
`tests/` (совпадает с `notes.md:48`).

## Замечания по безопасности

Изменений кода/конфигов нет; CSRF, rate limiting, `Cache-Control: no-store`,
проверки срока и TTL не затрагивались. Документы не добавляют секретов и не
раскрывают деталей криптографии. Замечаний нет.
