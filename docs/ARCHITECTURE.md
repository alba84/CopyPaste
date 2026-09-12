# ARCHITECTURE.md

Единый источник правды по целевой архитектуре CopyPaste. На этот документ
опираются все агенты команды. Изменять его можно только через ADR и гейт
подтверждения человеком (см. раздел «Эволюция стандартов»).

Статус: целевая архитектура. Миграция 002–007 завершена: контексты `Secret`,
`Crypto` и `Access` приведены к целевой структуре, бизнес-легаси `App\*` удалён
(остаются только технические слои `Kernel`, `Schedule`, `EventSubscriber`).
Остаточные шаги и известный долг перечислены в §2, §9 и
`docs/task/008-finalize-architecture-docs/notes.md`. Разделы §3–§7 описывают
целевое состояние и остаются обязательным стандартом.

## 1. Цели и принципы

1. **Сначала простая архитектура, которая выдерживает рост.** CopyPaste —
   open-source/self-hosted сервис. Модульный монолит, а не микросервисы.
2. **Домен не знает о фреймворке.** Ни один класс из `Domain/` не импортирует
   Symfony, Doctrine, Twig или Sodium.
3. **Явные границы важнее слоёв.** Модуль (bounded context) — единица
   изменяемости; слои внутри модуля — единица тестируемости.
4. **Тестируемость — требование дизайна.** Зависимости от времени, случайности
   и внешнего мира передаются через порты и подменяются в тестах.
5. **Прагматизм без карго-культа.** Паттерн применяется, когда он уменьшает
   сложность, а не когда «так положено в DDD». См. раздел 5.

## 2. Текущее состояние (as-is)

Миграция 002–007 завершена: контексты `Secret`, `Crypto` и `Access` выделены в
целевой структуре §3.2, бизнес-легаси `App\*` удалён.

- `src/Secret/` — ядро (bounded context):
  - `Domain/` — агрегат `Secret`, VO (`Token`, `Ttl`, `Hint`, `Plaintext`,
    `SecretKey`, `EncryptedPayload`), доменные события, порты
    `SecretRepository`/`TokenGenerator`, доменные исключения. Фреймворк не
    импортируется.
  - `Application/` — use cases `CreateSecret`, `RevealSecret` (типизированный
    результат `Revealed|InvalidKey|NotFound`), `PurgeExpiredSecrets`.
  - `Infrastructure/` — DBAL-адаптер `DoctrineSecretRepository` с
    persistence-моделью `SecretRecord` и явным `SecretRecordMapper` (ADR 0002),
    ORM schema-anchor `SecretSchemaAnchor` (ADR 0003/0004), генератор токенов.
  - `Presentation/` — тонкие HTTP-контроллеры (`CreateSecretController`,
    `RevealSecretController`, `SecretCreatedController`) и консольный
    `PurgeExpiredSecretsCommand`.
- `src/Crypto/` — порт `Cryptography` (`Domain`) и Sodium-адаптер
  (`Infrastructure/Sodium/SodiumCryptography`).
- `src/Access/` — framework-free порт `AccessLimiter` (`Domain`) и
  Symfony-адаптер поверх `framework.rate_limiter` (`Infrastructure/Symfony`),
  вызываемый из Presentation модуля `Secret` в прежней точке и порядке.
- `src/Kernel.php`, `src/Schedule.php`, `src/EventSubscriber/` — минимальные
  технические слои bootstrap-инфраструктуры фреймворка, не бизнес-легаси.
- Слой `Shared` зарезервирован в deptrac; собственных классов пока не содержит.
- Персистентность: таблица `secret` (переименована миграцией
  `Version20260910000000` с сохранением данных), явные имена индексов
  `uniq_secret_token`/`idx_secret_expires_at`.
- Границы слоёв проверяются deptrac по целевому набору
  (`Secret_*`, `Crypto_*`, `Access_*`, `Shared` + технические слои); `make qa`
  зелёный.

Остаточные отличия от целевого состояния (вне миграции 002–007):

- burn-after-reading в агрегате ещё не реализован;
- публичные/инфраструктурные имена сохранены временно легаси
  (`app:paste:purge`, `app_paste_*`, формы `create_paste`/`unlock_paste`,
  `templates/paste/*`, `#paste-url`, том `paste_data`) ради неизменности
  наблюдаемого поведения — это технические идентификаторы, а не доменный язык
  (см. `docs/UBIQUITOUS_LANGUAGE.md`);
- владельцем ORM-схемы остаётся schema-anchor; переход на migrations-only
  отложен (открытый долг ADR 0003/0004);
- открытый follow-up backlog — `docs/task/008-finalize-architecture-docs/notes.md`.

## 3. Целевая архитектура: прагматичный модульный монолит

### 3.1 Карта контекстов

| Контекст | Тип | Статус | Отвечает за |
|---|---|---|---|
| `Secret` | Ядро | Есть | Создание, хранение, выдача, TTL, burn-after-reading |
| `Crypto` | Generic subdomain | Есть | KDF + AEAD, порт + Sodium-адаптер |
| `Access` | Supporting | Есть (rate limit) | Лимиты, квоты, защита от перебора |
| `Identity` | Supporting | Планируется | Аккаунты, владельцы записей, API-токены |
| `Audit` | Generic | Планируется | Журнал операций, модерация |
| `Notification` | Generic | Планируется | Email/Telegram-уведомления |
| `Attachments` | Supporting | Планируется | Файлы и вложения, blob-хранилище |

Правило: **не создавать модуль заранее**. Новый контекст выделяется, когда под
него появляется реальная задача и словарь. До этого он не существует в коде.

### 3.2 Структура каталогов

```text
src/
├── Secret/                                  # bounded context (модуль)
│   ├── Domain/
│   │   ├── Model/                           # агрегат и value objects
│   │   ├── Repository/SecretRepository.php  # порт (интерфейс)
│   │   ├── Service/                         # доменные политики
│   │   ├── Event/                           # доменные события
│   │   └── Exception/
│   ├── Application/
│   │   ├── CreateSecret/                    # use case целиком в одной папке
│   │   ├── RevealSecret/
│   │   └── PurgeExpiredSecrets/
│   ├── Infrastructure/
│   │   ├── Persistence/Doctrine/            # адаптер репозитория, маппинг
│   │   └── ...                              # прочие адаптеры портов
│   └── Presentation/
│       ├── Http/                            # контроллеры, формы, ответы
│       └── Console/                         # команды
├── Crypto/
│   ├── Domain/                              # порт Cryptography
│   └── Infrastructure/Sodium/               # адаптер на sodium_*
├── Access/                                  # rate limiting, квоты
├── Shared/                                  # минимальный kernel (Clock и т.п.)
└── Kernel.php
```

Каждый use case — папка с командой/запросом, обработчиком и DTO результата.
Имена папок отражают доменный язык, а не техническую роль.

### 3.3 Слои и направление зависимостей

```text
Presentation ──▶ Application ──▶ Domain
      │                │           ▲
      └────────────────┼───────────┘
                       │
              Infrastructure ──реализует порты──▶ Application / Domain
```

- `Domain` не зависит ни от чего, кроме PHP и PSR-интерфейсов.
- `Application` зависит только от `Domain` и портов (в т.ч. из `Crypto`).
- `Infrastructure` знает `Domain`/`Application` и реализует их порты.
- `Presentation` знает `Application` и `Domain`-исключения, но не
  `Infrastructure`.
- Сборка графа — в DI-конфигурации Symfony (composition root), не в домене.
- Между модулями виден только публичный контракт (`Application` API или
  опубликованный доменный порт). Залезать в `Infrastructure`/`Domain` чужого
  модуля запрещено.

Границы проверяются автоматически через deptrac (см. `docs/QUALITY.md`), а не
только на ревью.

## 4. Тактический DDD

### 4.1 Что применяем

- **Агрегат** `Secret` — единственная транзакционная граница модуля. Внутри:
  токен, подсказка, зашифрованная нагрузка, время создания/истечения, признак
  «одноразовый», признак «просмотрен». Инварианты — в методах агрегата.
- **Value Objects**: `Token`, `Ttl`, `Hint`, `Plaintext`, `SecretKey`,
  `EncryptedPayload`. Иммутабельные, `final readonly`, самовалидирующиеся при
  создании (`__construct` бросает доменное исключение на невалидное значение).
- **Репозиторий как порт**: интерфейс `SecretRepository` в `Domain/Repository`,
  реализация `DoctrineSecretRepository` в `Infrastructure/Persistence`.
  Наружу отдаётся только агрегат, не QueryBuilder и не Doctrine-сущность.
- **Доменные события**: `SecretCreated`, `SecretRevealed`, `SecretBurned`,
  `SecretExpired`. На старте только фиксируются на агрегате; публикация в
  Symfony Messenger включается, когда появляется реальный потребитель
  (Audit/Notification). Не строить eventual consistency заранее.
- **Доменные политики/сервисы**: логика, не принадлежащая одному агрегату
  (например, генерация токена с гарантией уникальности). Без суффиксов
  `Manager`/`Helper`.
- **Порты** для недетерминизма: `Clock` (`Psr\Clock\ClockInterface`),
  `TokenGenerator`, `Cryptography`. Их реализации инъектируются, поэтому
  домен и use case детерминированно тестируются.

### 4.2 Допустимые упрощения

- Не вводить отдельный `Domain`-слой для CRUD-подобных мест без инвариантов.
- Не плодить VO там, где примитив не имеет поведения и не защищает инвариант.
- Не выделять слой Application, если сценарий — один вызов репозитория без
  оркестрации; но контроллер всё равно не занимается бизнес-логикой.
- Не внедрять event bus, пока нет второго потребителя.

### 4.3 Осознанно НЕ применяем сейчас

| Паттерн | Когда станет уместен |
|---|---|
| Event Sourcing | Если понадобится полный аудит изменений и темпоральные запросы |
| CQRS / read-модели | При появлении тяжёлых списков и отчётности |
| Sagas / process managers | Когда появятся многошаговые распределённые процессы |
| Микросервисы | При независимом масштабировании контекстов и отдельной команде |
| Абстрактная шина событий | При 2+ реальных подписчиках доменных событий |

## 5. Границы с фреймворком и персистентность

- Doctrine-маппинг не живёт на доменном агрегате. Предпочтительный вариант —
  XML-маппинг в `Infrastructure/Persistence/Doctrine/Mapping` **или** отдельная
  persistence-модель + маппер, когда маппинг VO/BLOB нетривиален. Стандарт:
  для `Secret` с несколькими VO использовать отдельную persistence-модель и
  явный маппер. При конфликте ORM-маппинга с легаси-схемой persistence-модель
  может быть DBAL-моделью (`final readonly`, без ORM-атрибутов) с явным
  маппером; выбор для `Secret` зафиксирован ADR 0002.
- ORM-владение схемой таблицы `secret` после удаления легаси-сущности
  `App\Entity\Paste` переходит к минимальному schema-anchor в
  `Secret/Infrastructure` — ORM-классу `SecretSchemaAnchor`, отображающему
  `secret` и используемому только инструментами схемы (`SchemaTool`,
  `doctrine:schema:validate`); рантайм-персистентность остаётся за DBAL-адаптером
  `DoctrineSecretRepository`. Владение схемой и контроль дрейфа — ADR 0003.
- Явные имена индексов `secret` закреплены одновременно в ORM-анкоре
  (`#[ORM\UniqueConstraint(name: 'uniq_secret_token', ...)]`,
  `#[ORM\Index(name: 'idx_secret_expires_at', ...)]`) и в миграции: авто-хеш
  Doctrine (`UNIQ_<hash(table,column)>`) не используется, чтобы имена не
  зависели от имени таблицы. Переименование `paste → secret` и политика имён
  зафиксированы ADR 0004.
- Кастомные Doctrine-типы для VO (`TokenType`, `EncryptedPayloadType`) — в
  `Infrastructure`. Знание о SQL-представлении не протекает в домен.
- Смена СУБД (SQLite → PostgreSQL) должна оставаться заменой адаптера и
  конфигурации. Домен и Application не содержат SQL и специфичных для SQLite
  вызовов. `PRAGMA`-настройки — в инфраструктурном subscriber'е.
- Миграции — отдельный артефакт, коммитится вместе с изменением модели.

## 6. Обработка ошибок

- Нарушение инварианта — доменное исключение (`Domain/Exception`), наследник
  общего `DomainException` модуля. Их не ловят «на всякий случай».
- Ожидаемая бизнес-альтернатива — не исключение, а результат. Пример: неверный
  ключ в `RevealSecret` даёт типизированный результат `Revealed|InvalidKey`,
  а не `null` из глубины.
- Маппинг исключений в HTTP-коды — в `Presentation` (listener/подписчик).
  Домен ничего не знает о 404/422.
- Запрещено глотать `Throwable` и логировать секреты, plaintext и производные
  ключи (жёсткое правило проекта).

## 7. Тестируемость как дизайн

- Домен тестируется чистыми unit-тестами без контейнера: все зависимости —
  порты.
- Application-сценарии тестируются с in-memory реализациями портов
  (`InMemorySecretRepository`, фиксированные `Clock` и `TokenGenerator`).
- Инфраструктура (Doctrine-маппинг, Sodium-адаптер) тестируется интеграционно.
- Полные HTTP-сценарии — functional-тестами (`WebTestCase`).
- Тест-план закладывается архитектором в `plan.md` до реализации; тесты
  пишутся вместе с кодом, а не «потом».

## 8. Эволюция стандартов

1. Изменение архитектурного правила оформляется ADR (`docs/adr/NNNN-*.md`) и
   отдельной задачей.
2. Архитектор предлагает изменение, оркестратор останавливает пайплайн и
   запрашивает подтверждение человека (гейт G2).
3. После одобрения обновляется этот документ, ADR получает статус `Accepted`.
4. Только затем правила применяются в коде. Молчаливое нарушение стандарта
   запрещено — это блокирующее замечание ревью.

## 9. Стратегия миграции

Принцип «новое — по стандартам, старое — по мере касания» применялся
инкрементально задачами 002–007 (`docs/task/ROADMAP.md`):

1. Каждая задача создавала код строго в целевой структуре; легаси не
   переписывался оптом.
2. Эталонный срез — задача 002: use case `CreateSecret`, тонкий
   `PasteController`, фиксация персистентности (ADR 0002) и целевого deptrac.
3. По тому же образцу извлечены `RevealSecret` (003), `PurgeExpiredSecrets`
   (004) и `created` (005a); контекст `Access` выделен в 007.
4. Историческое легаси `PasteController`, `App\Entity\Paste`,
   `App\Repository\PasteRepository`, `App\Service\EncryptionService` и старые
   `Dto`/`Form` удалены в 005a.
5. Таблица `paste → secret` переименована одной миграцией с сохранением данных
   (006, ADR 0004).
6. Границы модулей закреплены целевым deptrac (`docs/QUALITY.md` §2.2) и
   проверяются в `make qa`.

Миграция 002–007 **завершена**: модуль `Secret` покрывает все сценарии ядра.

Остаточные шаги (не часть 002–007; требуют отдельных задач и, где указано,
ADR):

1. **Burn-after-reading** — не реализован в агрегате; требует доменного
   решения, ADR и миграции БД (признаки «одноразовый»/«просмотрен»).
2. **Полное переименование публичных имён** `app:paste:*`, `app_paste_*`, форм
   `create_paste`/`unlock_paste`, `templates/paste/*`, `#paste-url`, тома
   `paste_data` в терминологию `Secret` — отдельная задача без изменения
   поведения (URL и wire-контракты на первом шаге сохраняются).
3. **Владение схемой:** переход на migrations-only (удаление ORM schema-anchor)
   — открытый долг ADR 0003/0004, отдельный ADR и пересмотр `docs/QUALITY.md` §4.
4. **Ops:** автоматический откат/restore БД при деплое (`deploy.sh` не вызывает
   `down()`) — страховка для отката после миграции `paste → secret` (ADR 0004).
5. **Quality:** базовый `TestCase`/`phpstan/phpstan-phpunit` вместо роста
   `@phpstan-ignore` в тестах; вынести `migrations/` из PHPStan `paths` либо
   типизировать их.
6. **Access:** доверенные прокси/`getClientIp()`, квоты и контекст `Identity`.

Подробный backlog и обоснования — в
`docs/task/008-finalize-architecture-docs/notes.md`.
