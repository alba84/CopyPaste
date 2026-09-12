# ROADMAP: миграция CopyPaste к целевой архитектуре

Живой документ оркестратора. Показывает весь путь от текущего плоского
`App\*` к целевому модульному монолиту из `docs/ARCHITECTURE.md` §3–7 и
позволяет двигаться поэтапно, отмечая выполненные задачи.

## Обозначения

- `[ ]` — не начато; `[~]` — в работе; `[x]` — выполнено (артефакты в
  `docs/task/<id>/`, `make qa` зелёный, гейт G3 пройден человеком).
- Каждая задача проходит полный пайплайн с гейтами G1/G2/G3 по
  `docs/task/README.md`.
- Порядок задач отражает зависимости, а не жёсткий план: архитектор может
  предложить дробление/слияние на G2.

## Принципы миграции

1. **Инкрементально** (`docs/ARCHITECTURE.md` §9): новое — по стандартам,
   легаси — по мере касания. Никакого big-bang.
2. **Поведение неизменно**: маршруты и URL, формат ссылок, тексты ошибок,
   формат шифрования, схема БД (кроме отдельно согласованной миграции).
3. **Границы проверяемы**: `deptrac` описывает целевые слои и ужесточается по
   мере появления модулей.
4. **Без преждевременных модулей** (§3.1): `Identity`, `Audit`,
   `Notification`, `Attachments` не создаются, пока под них нет задачи и
   словаря.
5. **Домен без фреймворка** (§3.3): `Domain`/`Application` не импортируют
   Symfony/Doctrine/Sodium.
6. **Доменные события** только фиксируются на агрегате; шина не вводится, пока
   нет второго реального потребителя (§4.1).

## Статус задач

| Задача | Статус | Название | Зависит от | Триггеры для человека |
|---|---|---|---|---|
| `001` | `[x]` | quality-tooling-bootstrap (QA-набор, PHPStan L10, deptrac as-is) | — | — |
| `002` | `[x]` | extract-create-secret — **эталонный срез** | 001 | крипто-формат; стратегия персистентности; deptrac целевые слои |
| `003` | `[x]` | extract-reveal-secret | 002 | безопасность (no-store, нейтральные ошибки) |
| `004` | `[x]` | extract-purge-expired-secrets | 002 | — |
| `005` | `[x]` | remove-legacy-paste (005a) — без миграции | 003, 004 | удаление легаси; целевой deptrac |
| `006` | `[ ]` | rename-paste-table-to-secret (005b) | 005 | миграция БД, переименование таблицы |
| `007` | `[ ]` | extract-access-rate-limiting (контекст `Access`) | 006 | изменение лимитов/квот |
| `008` | `[ ]` | finalize-architecture-docs (as-is, глоссарий, changelog) | 007 | правка `docs/ARCHITECTURE.md` |

Легенда статусов задач: `[ ]` не начато, `[~]` в работе (на одном из гейтов),
`[x]` выполнено.

## Содержание задач

### 002 — extract-create-secret (эталон)

Первый срез по `docs/ARCHITECTURE.md` §9.3, фиксирующий эталон структуры.

- Создаёт каркас: `src/Secret/{Domain,Application,Infrastructure,Presentation}`,
  `src/Crypto/{Domain,Infrastructure}`, `src/Shared`.
- Домен: агрегат `Secret`, VO (`Token`, `Ttl`, `Hint`, `Plaintext`,
  `SecretKey`, `EncryptedPayload`), доменные события фиксируются на агрегате.
- Порты: `Cryptography`, `Clock` (`Psr\Clock`), `TokenGenerator`,
  `SecretRepository`; адаптеры — Sodium и Doctrine (persistence-модель +
  маппер по §5).
- Application: use case `CreateSecret`; Presentation: тонкий контроллер и
  форма; `PasteController::create` перестаёт делать бизнес-логику.
- `deptrac.yaml` описывает новые namespace; правила для легаси сохраняются.
- **Вне объёма**: Reveal, Purge, rate limiting, удаление легаси Reveal.

### 003 — extract-reveal-secret

- Use case `RevealSecret` с типизированным результатом
  `Revealed | InvalidKey | NotFound`; «нет записи» и «истекло» → единый
  нейтральный `404`.
- `Cache-Control: no-store`, нейтральные сообщения; wire-контракт
  `unlock_paste` сохранён.
- Перенос `UnlockPasteType`/`UnlockPasteData`; `PasteController::view` удаляется.
- **Burn-after-reading — вне объёма 003** (сейчас не реализован; выносится в
  отдельную задачу + ADR с миграцией БД, G1 задачи 003 Q1-A).

### 004 — extract-purge-expired-secrets

- Use case `PurgeExpiredSecrets` + консольный адаптер.
- Легаси `PurgeExpiredPastesCommand` и запрос из `PasteRepository` вытесняются.

### 005 — remove-legacy-paste (005a, без миграции)

- Перенос `PasteController::created` (`/created/{token}`) в
  `Secret/Presentation/Http` на `SecretRepository`; удаление `PasteController`.
- Удаление `Entity\Paste`, `Repository\PasteRepository`, старых `Dto`/`Form`,
  `Service\EncryptionService`, `config/services_test.yaml`.
- Удаление легаси-тестов/перевод интеропа на новый код.
- `deptrac.yaml` переводится на целевой набор без слоёв `App\*` (через ADR,
  если это меняет стандарт).
- **Без изменения схемы БД.**

### 006 — rename-paste-table-to-secret (005b)

- Отдельная миграция `ALTER TABLE paste RENAME TO secret` (данные сохраняются).
- Обновление `DoctrineSecretRepository`/маппера и ADR 0002/схемы.
- **Триггер:** миграция БД — подтверждение человека на G1/G2.

### 007 — extract-access-rate-limiting

- Контекст `Access`: порт + адаптер rate limiting, лимиты через
  `config/services.yaml`, интеграция в use case'ы.
- Легаси-лимитеры из контроллеров удаляются.

### 008 — finalize-architecture-docs

- Актуализация `docs/ARCHITECTURE.md` (раздел «Текущее состояние»),
  `docs/UBIQUITOUS_LANGUAGE.md`, ADR; changelog для пользовательских изменений.

## Вне объёма миграции

- Новые контексты `Identity`, `Audit`, `Notification`, `Attachments` — только
  под будущие продуктовые задачи и словарь (ARCHITECTURE §3.1).
- Event bus / eventual consistency — при появлении второго потребителя (§4.3).
- Смена СУБД, CQRS, микросервисы и прочее из «осознанно НЕ применяем» (§4.3).

## Журнал прогресса

| Дата | Задача | Событие |
|---|---|---|
| — | 001 | Утверждён и влит QA-набор (`7b969f7`, `7084efc`) |
| — | 002 | Roadmap создан, задача передана аналитику (G1) |
| — | 002 | G1 утверждён (Q1–Q6, все A); G2 утверждён (public plan.md + ADR 0002); в работе |
| — | 002 | Реализовано (Ш1–Ш10), `make qa`/`make coverage`/`schema:validate` зелёные; ревью `approved`; коммит выполнен |
| — | 003 | G1 утверждён (Q1–Q7, все A; burn-after-reading вне объёма); в работе |
| — | 003 | G2 утверждён (plan.md + query `LoadRevealSecret`); в работе |
| — | 003 | Реализовано (Ш1–Ш4), `make qa`/`make coverage`/`schema:validate` зелёные; ревью `approved`; коммит выполнен |
| — | 004 | G1 утверждён (Q1–Q6, все A); в работе |
| — | 004 | Реализовано (Ш1–Ш4), `make qa`/`make coverage`/`schema:validate` зелёные; ревью `approved`; коммит выполнен |
| — | 005/006 | Задача 005 разделена на 005a (remove-legacy-paste) и 005b (rename-paste-table-to-secret, миграция БД) |
| — | 005 | G1 утверждён (Q1-A: ORM schema-anchor + ADR 0003; Q2–Q6 = A); в работе |
| — | 005 | G2 утверждён (plan.md + ADR 0003); ревью: B1 (final ORM) исправлен, повторно `approved`; коммит выполнен |
