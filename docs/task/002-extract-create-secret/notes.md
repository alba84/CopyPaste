# Заметки: 002-extract-create-secret

Статус на момент перезапуска opencode: **реализовано, протестировано, отревьюено;
остались коммиты (делает человек / team-lead после смены permission) и переход к 003.**

## Что сделано

- G1 утверждён (Q1–Q6 = A), G2 утверждён (plan.md + ADR 0002).
- Реализация Ш1–Ш10:
  - `src/Secret` — агрегат `Secret`, VO (`Token`, `Ttl`, `Hint`, `Plaintext`,
    `SecretKey`, `EncryptedPayload`), событие `SecretCreated`, порты
    `SecretRepository`/`TokenGenerator`, use case `CreateSecret`, тонкий
    Presentation с wire-контрактом `create_paste`.
  - `src/Crypto` — порт `Cryptography` + `SodiumCryptography` (байт-совместим
    с `App\Service\EncryptionService`).
  - Персистентность — `SecretRecord` + `SecretRecordMapper` +
    `DoctrineSecretRepository` (DBAL) в таблицу `paste`, без второго ORM-маппинга.
  - Легаси: у `PasteController` удалён только `create`; `created`/`view` и
    Reveal не тронуты (Q6-A).
- Стандарты: ADR 0002 → `accepted`; обновлены `docs/QUALITY.md` §2.2 и
  `docs/ARCHITECTURE.md` §5; `deptrac.yaml` — целевые слои.

## Верификация (полный чистый прогон, выполнен оркестратором)

- `make qa` — зелёный: cs-fix 0/78, PHPStan L10 no errors, deptrac
  0 violations, PHPUnit **OK (89 tests, 337 assertions)**.
- `make coverage` — **100.00%** для `Secret/Domain`, `Secret/Application`,
  `Crypto/Domain` (порог 90%).
- `doctrine:schema:validate --env=test` — Mapping OK, DB schema in sync.
- Ревью `review.md` → **approved**, 0 блокирующих, 11 неблокирующих.

## Что осталось закоммитить (рабочее дерево, НЕ закоммичено)

Правило `git commit*` в `.opencode/agent/team-lead.md` было `deny`; после
перезапуска — `ask`. Рабочее дерево:

```
 M config/routes.yaml
 M config/services.yaml
 M deptrac.yaml
 M docs/ARCHITECTURE.md
 M docs/QUALITY.md
 M src/Controller/PasteController.php
?? docs/adr/0002-secret-persistence-and-deptrac.md
?? docs/task/002-extract-create-secret/
?? docs/task/ROADMAP.md
?? src/Crypto/
?? src/Secret/
?? tests/Functional/CreateSecretFlowTest.php
?? tests/Functional/SecretWiringTest.php
?? tests/Integration/
?? tests/Support/
?? tests/Unit/Crypto/
?? tests/Unit/Secret/
```

Предложенные атомарные коммиты:

```bash
git add deptrac.yaml docs/QUALITY.md && git commit \
  -m "chore: add target module layers to deptrac"

git add src/ config/ && git commit \
  -m "feat: extract CreateSecret use case into Secret module"

git add tests/ && git commit \
  -m "test: cover create-secret unit, integration and http flows"

git add docs/adr/0002-secret-persistence-and-deptrac.md docs/ARCHITECTURE.md docs/task/ && git commit \
  -m "docs: add task 002 artifacts and accepted ADR 0002"
```

`ROADMAP.md` уже отмечен: `002` → `[x]`.

## Неблокирующие замечания ревью (в `review.md`)

- `@phpstan-ignore` вырос 3→11 (все в `tests/`, `property.uninitialized`,
  с причиной и датой) — кандидат на follow-up.
- `plan.md` стр. 287 неточно описывает trim `hint` на wire (AC корректен,
  поведение совпадает с легаси).
- `SodiumCryptography::decrypt` отвергает пустой plaintext (легаси вернул бы
  `''`) — учесть в 003.
- Легаси `Paste::getEncryptedPayload()` не идемпотентен — риск для 003.
- TOCTOU проверки уникальности токена (низкий риск).

## Следующий шаг

После коммитов — задача `003-extract-reveal-secret` (см. `docs/task/ROADMAP.md`):
RevealSecret с типизированным `Revealed|InvalidKey`, burn-after-reading,
`Cache-Control: no-store`, нейтральные ошибки, перенос `UnlockPasteType`/Data.
