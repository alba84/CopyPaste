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
| `003` | `[ ]` | extract-reveal-secret | 002 | безопасность (no-store, нейтральные ошибки, burn-after-reading) |
| `004` | `[ ]` | extract-purge-expired-secrets | 002 | — |
| `005` | `[ ]` | remove-legacy-paste + целевой deptrac | 003, 004 | удаление легаси, смена стандарта границ → ADR |
| `006` | `[ ]` | extract-access-rate-limiting (контекст `Access`) | 004, 005 | изменение лимитов/квот |
| `007` | `[ ]` | finalize-architecture-docs (as-is, глоссарий, ADR, changelog) | 005, 006 | правка `docs/ARCHITECTURE.md` |

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

- Use case `RevealSecret` с типизированным результатом `Revealed|InvalidKey`.
- Burn-after-reading, `Cache-Control: no-store`, нейтральные сообщения.
- Перенос `UnlockPasteType`/`UnlockPasteData`; `PasteController::view` тонкий.

### 004 — extract-purge-expired-secrets

- Use case `PurgeExpiredSecrets` + консольный адаптер.
- Легаси `PurgeExpiredPastesCommand` и запрос из `PasteRepository` вытесняются.

### 005 — remove-legacy-paste + целевой deptrac

- Удаление `PasteController`, `Entity\Paste`, `Repository\PasteRepository`,
  старых `Dto`/`Form`, `Service\EncryptionService`.
- `deptrac.yaml` переводится на целевые слои без `App\*`-остатков.
- Обновляется раздел «Текущее состояние» в `docs/ARCHITECTURE.md` (через ADR,
  если меняется стандарт).

### 006 — extract-access-rate-limiting

- Контекст `Access`: порт + адаптер rate limiting, лимиты через
  `config/services.yaml`, интеграция в use case'ы.
- Легаси-лимитеры из контроллера удаляются.

### 007 — finalize-architecture-docs

- Актуализация `docs/ARCHITECTURE.md`, `docs/UBIQUITOUS_LANGUAGE.md`, ADR;
  changelog-запись для пользовательских изменений.

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
