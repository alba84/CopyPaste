# QUALITY.md

Инструменты качества, целевые пороги и команды. Дополняет `AGENTS.md`.

## 1. Текущее состояние

| Инструмент | Статус | Замечание |
|---|---|---|
| PHPUnit 11.5 | Настроен | `make test` |
| PHPStan | Настроен | level 8 в `phpstan.neon`, ТЗ требует level 10 |
| PHP-CS-Fixer | Настроен | пресет `@Symfony`, `make cs-fix` |
| CI (GitHub Actions) | Настроен | `test → build → deploy` |
| deptrac / границы слоёв | Нет | добавить |
| Покрытие кода | Нет | добавить |
| Mutation testing | Нет | опционально, для домена |
| composer QA-скрипт | Нет | добавить |

## 2. Целевой набор

### 2.1 PHPStan — level 10

`phpstan.neon`:

```neon
includes:
    - vendor/phpstan/phpstan-symfony/extension.neon

parameters:
    level: 10
    paths: [src, tests]
    tmpDir: var/phpstan
    treatPhpDocTypesAsCertain: false
    checkUninitializedProperties: true
    reportUnmatchedIgnoredErrors: true
```

Добавить dev-зависимости:

```bash
composer require --dev phpstan/phpstan-doctrine \
    phpstan/phpstan-strict-rules \
    phpstan/phpstan-deprecation-rules
```

Баселайн не создаём: цель — ноль ошибок. Если легаси мешает — точечный
`@phpstan-ignore` с указанием причины и датой пересмотра.

### 2.2 Границы модулей — deptrac

```bash
composer require --dev deptrac/deptrac
```

`deptrac.yaml` (итоговый целевой набор зафиксирован ADR 0002):

```yaml
deptrac:
  paths: [src]
  layers:
    - name: Secret_Domain
      collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Domain\\#' }]
    - name: Secret_Application
      collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Application\\#' }]
    - name: Secret_Infrastructure
      collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Infrastructure\\#' }]
    - name: Secret_Presentation
      collectors: [{ type: classNameRegex, value: '#^App\\Secret\\Presentation\\#' }]
    - name: Crypto_Domain
      collectors: [{ type: classNameRegex, value: '#^App\\Crypto\\Domain\\#' }]
    - name: Crypto_Infrastructure
      collectors: [{ type: classNameRegex, value: '#^App\\Crypto\\Infrastructure\\#' }]
    - name: Shared
      collectors: [{ type: classNameRegex, value: '#^App\\Shared\\#' }]
  ruleset:
    Shared: []
    Secret_Domain: [Shared]
    Crypto_Domain: [Secret_Domain, Shared]
    Secret_Application: [Secret_Domain, Crypto_Domain, Shared, Psr]
    Crypto_Infrastructure: [Crypto_Domain, Secret_Domain, Shared]
    Secret_Infrastructure: [Secret_Domain, Secret_Application, Crypto_Domain, Shared, Doctrine]
    Secret_Presentation: [Secret_Application, Secret_Domain, Symfony, Psr]
  skip_violations: {}
```

### 2.3 PHP-CS-Fixer

Дополнить `.php-cs-fixer.dist.php` правилами:

```php
->setRules([
    '@Symfony' => true,
    'declare_strict_types' => true,
    'strict_comparison' => true,
    'strict_param' => true,
    'void_return' => true,
    'ordered_imports' => ['sort_algorithm' => 'alpha'],
    'no_unused_imports' => true,
    'global_namespace_import' => ['import_classes' => true],
])
```

`declare_strict_types` приведёт существующие файлы к стандарту при первом
запуске `make cs-fix`.

### 2.4 Покрытие кода

- Драйвер: PCOV в CI (быстрее Xdebug).
- Порог для `src/*/Domain` и `src/*/Application` — **не ниже 90%**.
- Общий порог не вводим: инфраструктурные адаптеры дешевле проверять
  интеграционно.

```xml
<coverage>
    <report><clover outputFile="var/coverage/clover.xml"/></report>
</coverage>
```

### 2.5 Mutation testing (опционально)

`infection/infection` только по домену; включаем после стабилизации структуры:

```bash
composer require --dev infection/infection
```

Цель: MSI ≥ 70% для `src/Secret/Domain`.

## 3. Команды

`Makefile`:

```make
qa: cs-check stan deptrac test

cs-check:
	docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

deptrac:
	docker compose exec php vendor/bin/deptrac analyse

coverage:
	docker compose exec -e APP_ENV=test php php bin/phpunit --coverage-clover var/coverage/clover.xml
```

`composer.json` (scripts):

```json
{
  "scripts": {
    "cs-check": "php-cs-fixer fix --dry-run --diff",
    "stan": "phpstan analyse",
    "deptrac": "deptrac analyse",
    "test": "phpunit",
    "qa": ["@cs-check", "@stan", "@deptrac", "@test"]
  }
}
```

## 4. CI

В job `test` добавить после PHPStan:

```yaml
- run: vendor/bin/deptrac analyse
- run: php bin/console doctrine:schema:validate --env=test
- run: php bin/phpunit --coverage-clover var/coverage/clover.xml
```

- CI обязан быть зелёным до merge.
- Деплой остаётся только по тегам `v*.*.*` (как сейчас).
- Секреты деплоя не попадают в логи.

## 5. Definition of Done для задачи

Задача считается готовой, когда:

1. `make qa` зелёный (cs-check, stan level 10, deptrac, tests).
2. Доменная логика покрыта unit-тестами; поведение из `requirements.md`
   подтверждено тестами.
3. Границы слоёв не нарушены.
4. Обновлены `docs/UBIQUITOUS_LANGUAGE.md` (новые термины) и, при
   необходимости, `docs/ARCHITECTURE.md` через ADR.
5. Ревью пройдено; артефакты задачи лежат в `docs/task/<id>/`.
6. Миграции (если есть) применяются с нуля и откатываются.
