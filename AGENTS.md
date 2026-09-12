# Repository Guidelines

## Standards & Agent Team

Источники правды по архитектуре и процессу:

- `docs/ARCHITECTURE.md` — целевая архитектура, границы модулей, DDD-паттерны.
- `docs/CONVENTIONS.md` — соглашения по коду, ошибкам и тестам.
- `docs/QUALITY.md` — инструменты, пороги и Definition of Done.
- `docs/UBIQUITOUS_LANGUAGE.md` — единый язык проекта.
- `docs/task/README.md` — артефакты задач, гейты и шаблоны.
- `docs/adr/` — архитектурные решения (ADR).

Конфигурации агентов — в `.opencode/agent/`. Новую задачу запускает команда
`/feature <описание>`, архитектурное решение — `/adr <описание>`. Агенты не
делают commit/push/deploy; это гейт человека.

## Project Structure & Module Organization

Application code lives in `src/` as a pragmatic modular monolith: `Secret/` (domain aggregate, use cases and HTTP/console adapters), `Crypto/` (framework-free `Cryptography` port with a Sodium adapter), `Access/` (rate limiting port and Symfony adapter), and the technical `Kernel.php`, `Schedule.php`, `EventSubscriber/` layers. Each module follows `Domain` / `Application` / `Infrastructure` / `Presentation` boundaries enforced by deptrac. `Secret/Infrastructure/Persistence/Doctrine` owns persistence (DBAL adapter plus an ORM schema anchor). Twig views are under `templates/`; browser assets are in `public/`. Database migrations belong in `migrations/`. Unit and HTTP-level tests live in `tests/Unit/` and `tests/Functional/`, with `tests/Integration/` for persistence and migration checks. Docker, Nginx, and PHP configuration is kept in `docker/`, `compose.yaml`, and `compose.prod.yaml`.

## Build, Test, and Development Commands

Run project commands inside Docker through the Makefile:

- `make up` builds and starts PHP-FPM, Nginx, and the scheduler.
- `make migrate` applies Doctrine migrations.
- `make test` runs the PHPUnit suite.
- `make stan` runs PHPStan.
- `make cs-fix` formats PHP using PHP-CS-Fixer.
- `make console app:paste:purge` removes expired records.
- `make down` stops the local environment.

The application is available at `http://localhost:8080`.

## Coding Style & Naming Conventions

Target PHP 8.4 and Symfony 7.4. Follow Symfony coding standards, four-space indentation, `declare(strict_types=1)` in every PHP file, typed properties, and explicit return types. Classes use PascalCase; methods and variables use camelCase. Name controllers `*Controller`, forms `*Type`, commands `*Command`, and tests `*Test`. Run `make qa` (PHP-CS-Fixer, PHPStan — target level 10, deptrac, PHPUnit) before submitting changes; see `docs/QUALITY.md`.

## Testing Guidelines

Use PHPUnit. Keep isolated cryptography behavior in unit tests and complete request/form/database scenarios in functional tests. Test method names should describe behavior, for example `testWrongKeyShowsNeutralError()`. Cover successful creation and decryption, invalid keys, validation, missing tokens, expiration, and cache headers.

## Commit & Pull Request Guidelines

Use small conventional commits matching repository history: `feat:`, `fix:`, `chore:`, and `ci:`. Keep each commit focused and include migrations with their model change. Pull requests should explain the behavior change, list verification commands, link relevant issues, and include screenshots for UI changes. CI must pass before merge.

## Security & Configuration

Never log or persist plaintext, secret keys, or derived encryption keys. Keep secrets in `.env.local`, never tracked files. Do not weaken CSRF, rate limiting, expiration checks, or `Cache-Control: no-store`. Verify Symfony, Doctrine, and Sodium APIs with Context7 before changing integrations.
