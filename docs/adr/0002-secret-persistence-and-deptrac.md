# 0002. Персистентность `Secret`: DBAL persistence-модель + mapper и целевой deptrac-набор

- Статус: accepted
- Дата: 2026-09-12
- Автор: architect
- Связанная задача: docs/task/002-extract-create-secret/

## Контекст

Задача 002 извлекает `CreateSecret` в модуль `Secret` (`docs/ARCHITECTURE.md`
§9.3) и фиксирует эталон персистентности для 003/004/005. Решение G1 Q1-A
требует отдельной persistence-модели и **явного маппера** на ту же таблицу
`paste`; легаси Reveal остаётся на ORM-сущности `App\Entity\Paste`.

Силы, действующие на решение:

1. `doctrine:schema:validate --env=test` и `SchemaTool` (используется
   functional-тестами) валидируют/создают схему по всем ORM-метаданным. Второй
   ORM-класс, отображённый на `paste`, создаёт риск дрейфа схемы, конфликта
   индексов и повторного `CREATE TABLE` при `SchemaTool::createSchema`.
2. `docs/ARCHITECTURE.md` §5 предписывает для `Secret` «отдельную
   persistence-модель и явный маппер», но не различает ORM-сущность и
   DBAL-модель.
3. Требования 002 (утверждены на G1) размещают `Plaintext`, `SecretKey`,
   `EncryptedPayload` в `Secret/Domain/Model`, поэтому порт
   `Crypto/Domain/Cryptography` и Sodium-адаптер ссылаются на типы
   `Secret_Domain`.
4. Q4-A добавляет целевые слои deptrac `Secret_*`/`Crypto_*`/`Shared`, сохраняя
   легаси `App\*` as-is. `docs/QUALITY.md` §2.2 называет ruleset «ориентиром,
   уточняется при появлении модулей» и не содержит правила для `Crypto_Domain`;
   deptrac по умолчанию запрещает любые неразрешённые зависимости.

## Рассмотренные варианты

1. **Второй ORM-маппинг на `paste`** (persistence-entity + mapper).
   Плюсы: единый ORM-стиль, автоматическая гидратация. Минусы: два маппинга
   одной таблицы, риск `schema:validate`/`SchemaTool`, дублирование метаданных;
   прямо противоречит формулировке Q1-A «легаси `Paste` — единственный
   ORM-класс».
2. **DBAL persistence-модель + явный маппер** (`SecretRecord` +
   `SecretRecordMapper` + `DoctrineSecretRepository` поверх
   `Doctrine\DBAL\Connection`).
   Плюсы: один ORM-маппинг, нулевой риск дрейфа схемы, полный контроль BLOB
   (`resource|string`) и преобразование дат; явный маппинг VO → колонки.
   Минусы: ручной SQL и типы, нет ORM-гидратации; нужно поддерживать при
   изменении схемы (миграции всё равно коммитятся с моделью).
3. **ACL: переиспользовать легаси `App\Entity\Paste`** (Q1-B).
   Плюсы: единственный маппинг. Минусы: временная зависимость
   `Secret_Infrastructure → App\Entity`, удаляется в 005; новый домен завязан на
   легаси-сущность.

## Решение

Выбран **вариант 2**. Adapter `DoctrineSecretRepository` пишет/читает таблицу
`paste` через `Doctrine\DBAL\Connection`; `SecretRecord` — обычный
`final readonly` persistence-объект (без ORM-атрибутов), `SecretRecordMapper`
явно выполняет `Secret ⇄ SecretRecord`. Второй ORM-маппинг не регистрируется,
`config/packages/doctrine.yaml` не меняется.

Fallback: если на G2 DBAL-подход признают неприемлемым, применяется вариант 3
(ACL на легаси `Paste`) на время до задачи 005.

Deptrac: закрепляется целевой набор слоёв и ацикличные правила:

```yaml
Shared: []
Secret_Domain: [Shared]
Crypto_Domain: [Secret_Domain, Shared]
Secret_Application: [Secret_Domain, Crypto_Domain, Shared, Psr]
Crypto_Infrastructure: [Crypto_Domain, Secret_Domain, Shared]
Secret_Infrastructure: [Secret_Domain, Secret_Application, Crypto_Domain, Shared, Doctrine]
Secret_Presentation: [Secret_Application, Secret_Domain, Symfony, Psr]
```

`Psr` у `Secret_Application` необходим для `Psr\Clock\ClockInterface`, который
инжектит `CreateSecretHandler`; зависимость от PSR-интерфейсов разрешена
`docs/ARCHITECTURE.md` §3.3.

`Crypto_Domain → Secret_Domain` — следствие размещения
`Plaintext`/`SecretKey`/`EncryptedPayload` в `Secret/Domain/Model` (требования
G1): порт возвращает/принимает эти VO. Это сознательное принятие связности
generic-контекста `Crypto` с ядром `Secret`; направление ациклично. Если позже
потребуется обратная независимость — перенос VO в `Crypto/Domain` оформляется
новым ADR. Слой `Shared` заводится как зарезервированный; классов в 002 не
содержит.

## Последствия

- Проще: `doctrine:schema:validate` и `SchemaTool` не затронуты; смена СУБД
  остаётся заменой адаптера; BLOB-семантика (`resource|string`) обрабатывается
  явно и одинаково в обоих направлениях интеропа с легаси.
- Сложнее: ручное сопровождение SQL/типов в `DoctrineSecretRepository` и
  парсинг дат в маппере; ответственность за синхронность со схемой лежит на
  адаптере и миграциях.
- 003 (Reveal) реализует чтение через тот же `SecretRecordMapper`; 005 при
  удалении легаси переименует таблицу `paste → secret` одной миграцией, не
  меняя домен.
- `Crypto_Domain` зависит от `Secret_Domain`; при переносе VO потребуется новый
  ADR.

## Совместимость со стандартами

После принятия ADR (гейт G2) обновляются:

- `docs/QUALITY.md` §2.2 — итоговый `deptrac.yaml` ruleset, включая
  `Crypto_Domain` и цепочку `Secret_*`.
- `docs/ARCHITECTURE.md` §5 — уточняющая строка: persistence-модель для
  `Secret` может быть DBAL-моделью с явным маппером, если ORM-маппинг
  конфликтует с легаси-схемой.

До одобрения правила применяются как согласованные на G1 (Q1-A, Q4-A).

## Актуализация

Переименование таблицы `paste → secret`, упомянутое в «Последствиях», выполнено
задачей 006 и зафиксировано ADR 0004: `DoctrineSecretRepository` работает с
таблицей `secret`, а schema-anchor переименован в `SecretSchemaAnchor`. DBAL
persistence-модель `SecretRecord`, явный `SecretRecordMapper` и имена колонок не
изменились — решение этого ADR сохраняет силу.
