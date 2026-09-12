# Заметки: 001-quality-tooling-bootstrap

## Ход пайплайна и гейты

- **G1 (требования)** — `requirements.md` подтверждён человеком; решения Q1–Q6
  зафиксированы в самом документе (раздел «Решения, утверждённые на G1»).
- **G2 (план)** — `plan.md` подтверждён; решения вынесены в раздел «Решения,
  утверждённые на G2»: `deptrac/deptrac`, разрешение `phpstan-phpunit` только
  при необходимости, согласование `tools/check-coverage.php` и
  `tests/object-manager.php`, критерий эскалации (> ~5 ignore / смена
  сигнатур/схемы — стоп).
- **G3 (коммит)** — итог принят человеком; коммит и push выполняет человек.
  Агенты не коммитили.

## Итог качества

- `make qa`, `composer qa`, `make coverage`, `doctrine:schema:validate --env=test`
  — exit 0 (тестировщик и QA-инженер).
- PHPStan level 10, 0 ошибок, baseline отсутствует.
- `@phpstan-ignore` — 3, только в тестах, формат `(причина; review YYYY-MM-DD)`.
- Ревьюер: `approved`, 0 блокирующих, 8 неблокирующих.
- `docs/UBIQUITOUS_LANGUAGE.md` не менялся — новых доменных терминов нет.
- Changelog не требуется — изменение инструментальное, не пользовательское.

## Follow-up (не входит в объём 001, вынесено в отдельные задачи)

1. **Флейки `PasteFlowTest`** — rate limiter `create_paste` хранит окно в
   filesystem (`var/cache/test/pools/app`); повторные прогоны `make test`
   в пределах ~минуты дают ложное «Слишком много запросов». Пре-существующий
   дефект, диффом 001 не порождён. Изолировать лимитер в test-среде.
2. **`tools/check-coverage.php`** — различать «каталогов Domain/Application
   нет» (exit 0) и «каталог есть, но statements = 0» (должно падать), чтобы
   порог не был фиктивным при рассинхроне путей clover.
3. **`composer stan`/`composer qa` не прогревают dev-контейнер** для
   `symfony.containerXmlPath`; прогрев есть только в `make stan`. На холодном
   checkout `composer qa` может упасть. В CI создаётся `composer install`.
4. **Дрейф `docs/QUALITY.md`** — в документе нет `-e XDEBUG_MODE=off`, который
   есть в `Makefile` (таргет `coverage`). Синхронизировать.
5. **`@phpstan-ignore` в тестах** (3 шт.) — пересмотреть после появления
   `phpstan-phpunit` или иной типизации PHPUnit lifecycle (дата review
   2026-12-31).

## Как воспроизвести проверки

```bash
make up
docker compose exec -T php rm -rf var/cache/test/pools/app   # сброс окна rate limiter
make qa
make coverage
```
