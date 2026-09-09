# Repository Guidelines

## Project Structure & Module Organization

Application code lives in `src/`: controllers handle HTTP flows, form types and DTOs validate input, `Entity/` and `Repository/` manage encrypted paste storage, and `Service/EncryptionService.php` is the only place that should call Sodium. Twig views are under `templates/`; browser assets are in `public/`. Database migrations belong in `migrations/`. Unit and HTTP-level tests live in `tests/Unit/` and `tests/Functional/`. Docker, Nginx, and PHP configuration is kept in `docker/`, `compose.yaml`, and `compose.prod.yaml`.

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

Target PHP 8.4 and Symfony 7.4. Follow Symfony coding standards, four-space indentation, strict types in new PHP files, typed properties, and explicit return types. Classes use PascalCase; methods and variables use camelCase. Name controllers `*Controller`, forms `*Type`, commands `*Command`, and tests `*Test`. Run PHP-CS-Fixer and PHPStan level 8 before submitting changes.

## Testing Guidelines

Use PHPUnit. Keep isolated cryptography behavior in unit tests and complete request/form/database scenarios in functional tests. Test method names should describe behavior, for example `testWrongKeyShowsNeutralError()`. Cover successful creation and decryption, invalid keys, validation, missing tokens, expiration, and cache headers.

## Commit & Pull Request Guidelines

Use small conventional commits matching repository history: `feat:`, `fix:`, `chore:`, and `ci:`. Keep each commit focused and include migrations with their model change. Pull requests should explain the behavior change, list verification commands, link relevant issues, and include screenshots for UI changes. CI must pass before merge.

## Security & Configuration

Never log or persist plaintext, secret keys, or derived encryption keys. Keep secrets in `.env.local`, never tracked files. Do not weaken CSRF, rate limiting, expiration checks, or `Cache-Control: no-store`. Verify Symfony, Doctrine, and Sodium APIs with Context7 before changing integrations.
