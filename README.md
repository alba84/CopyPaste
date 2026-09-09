# CopyPaste

Анонимный сервис для временной передачи зашифрованного текста по ссылке. Регистрация не требуется: отправитель задаёт секретный ключ, а получатель вводит его отдельно для расшифровки.

> Секретный ключ не отправляется и не сохраняется сервером. Передавайте его получателю по другому защищённому каналу.

## Возможности

- шифрование текста через Argon2id и Sodium Secretbox;
- уникальные ссылки без регистрации и авторизации;
- необязательная подсказка к ключу;
- автоматическое удаление записей по TTL;
- нейтральный ответ для отсутствующих и истёкших записей;
- CSRF-защита и ограничение частоты запросов;
- SQLite с WAL без отдельного контейнера базы данных;
- адаптивный интерфейс на русском языке;
- автоматические тесты, сборка контейнеров и deployment через GitHub Actions.

## Технологии

PHP 8.4, Symfony 7.4 LTS, Doctrine ORM, Twig, SQLite, Sodium, Nginx, Docker Compose, PHPUnit, PHPStan и PHP-CS-Fixer.

## Быстрый старт

Потребуются Docker Desktop с Compose и `make`.

```bash
git clone https://github.com/alba84/CopyPaste.git
cd CopyPaste

docker compose build php
docker compose run --rm php composer install
make up
make migrate
```

Откройте [http://localhost:8080](http://localhost:8080). Последующие запуски выполняются короче:

```bash
make up
make migrate
```

Остановить окружение:

```bash
make down
```

## Как это работает

1. Отправитель вводит текст, необязательную подсказку и секретный ключ.
2. Сервер создаёт случайные salt и nonce, выводит ключ шифрования через Argon2id и шифрует текст Secretbox.
3. В SQLite сохраняются только ciphertext, salt, nonce, метаданные и случайный 32-символьный token.
4. Получатель открывает ссылку `/p/{token}` и вводит ключ.
5. Secretbox одновременно проверяет ключ и целостность данных. Расшифрованный текст не сохраняется и возвращается с `Cache-Control: no-store`.

## Конфигурация

Локальные и production-секреты храните в `.env.local` или в отдельном production-файле `.env`. Не коммитьте реальные значения.

- `APP_SECRET` — секрет Symfony; заменить обязательно.
- `DEFAULT_URI` — базовый URL для абсолютных ссылок; по умолчанию `http://localhost:8080`.
- `APP_PASTE_TTL_DAYS` — срок хранения записи; по умолчанию `7`.
- `APP_KDF_OPSLIMIT` — вычислительный лимит Argon2id; по умолчанию `3`.
- `APP_KDF_MEMLIMIT` — память Argon2id в байтах; по умолчанию `268435456`.
- `DATABASE_URL` — путь к SQLite; по умолчанию `var/data/app.db`.
- `XDEBUG_MODE` — режим Xdebug в dev-контейнере; по умолчанию `off`.

Для слабой локальной машины допустимы интерактивные лимиты KDF: `APP_KDF_OPSLIMIT=2` и `APP_KDF_MEMLIMIT=67108864`. Не снижайте параметры production без оценки риска перебора ключей.

## Команды разработки

```bash
make up                    # собрать и запустить окружение
make migrate               # применить миграции Doctrine
make test                  # запустить PHPUnit
make stan                  # запустить PHPStan
make cs-fix                # отформатировать PHP
make console cache:clear   # выполнить Symfony Console-команду
make console app:paste:purge
make sh                    # открыть shell PHP-контейнера
make down                  # остановить окружение
```

Все Composer-, Symfony- и QA-команды выполняются внутри Docker.

## Структура проекта

```text
src/                    контроллеры, DTO, формы, сущности и сервисы
templates/              Twig-шаблоны
public/                 front controller, CSS и JavaScript
migrations/             миграции Doctrine
tests/Unit/             unit-тесты криптографии
tests/Functional/       HTTP-, form- и database-сценарии
docker/                 конфигурация PHP и Nginx
compose.yaml            локальное окружение
compose.prod.yaml       production-окружение
.github/workflows/      CI/CD
```

## Проверка качества

Перед отправкой изменений выполните:

```bash
make test
make stan
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec php php bin/console doctrine:schema:validate
```

Тесты покрывают roundtrip шифрования, неверный ключ, уникальность salt/nonce, Unicode и большой текст, создание записи, расшифровку, валидацию, 404 и истечение TTL.

## Production

Собираются два образа:

- `ghcr.io/alba84/copypaste` — PHP-FPM и приложение;
- `ghcr.io/alba84/copypaste-nginx` — Nginx и публичные assets.

На сервере подготовьте каталог с `compose.prod.yaml`, `docker/nginx/default.conf` и production `.env`, затем:

```bash
docker compose -f compose.prod.yaml pull
docker compose -f compose.prod.yaml up -d
docker compose -f compose.prod.yaml exec -T php bin/console doctrine:migrations:migrate -n
```

SQLite хранится в named volume `paste_data` и переживает пересоздание контейнеров. Для публичного домена разместите Caddy или другой TLS reverse proxy перед Nginx.

## CI/CD

Workflow запускается на push и pull request:

1. `test`: Composer, PHP-CS-Fixer, PHPStan, миграции и PHPUnit.
2. `build`: для push в `main` публикует PHP и Nginx образы в GHCR.
3. `deploy`: подключается к production-серверу по SSH, обновляет контейнеры и применяет миграции.

Необходимые GitHub Secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`. Сделайте GHCR-пакеты публичными либо заранее выполните `docker login ghcr.io` на сервере.

## Резервное копирование

База находится в `var/data/app.db` локально и в volume `paste_data` на production. Для согласованного бэкапа остановите сервис и скопируйте `app.db`. При копировании без остановки сохраняйте вместе файлы `app.db`, `app.db-wal` и `app.db-shm`.

## Безопасность

- Не логируйте plaintext, секретные и производные ключи.
- Не добавляйте аналитику или внешние запросы на страницы с данными.
- Не отключайте CSRF, rate limiting, TTL или `no-store`.
- Используйте HTTPS на публичном сервере.
- Выбирайте длинные непредсказуемые ключи: сервер не может восстановить забытый ключ.

## Участие в разработке

Правила структуры, тестирования и коммитов описаны в [AGENTS.md](AGENTS.md). Используйте небольшие conventional commits: `feat:`, `fix:`, `chore:`, `ci:`.
