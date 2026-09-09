# ТЗ: CopyPaste — анонимный зашифрованный обмен текстом

Репозиторий: https://github.com/alba84/CopyPaste.git

## 1. Общее описание

Веб-сервис для передачи текста по ссылке. Без регистрации и авторизации. Пользователь вставляет текст, опционально подсказку и обязательный секретный ключ (произвольная строка). Сервис шифрует текст этим ключом, сохраняет и выдаёт уникальную ссылку. Получатель по ссылке видит подсказку (если есть) и поле для ввода ключа; введя верный ключ, получает расшифрованный текст. Текст хранится **только** в зашифрованном виде; сервер ключ не сохраняет — расшифровка возможна только при повторном вводе ключа. Записи живут 7 дней (настраивается), после чего удаляются.

## 2. Инструкции для агента-разработчика

- **Работа с файлами проекта — только через MCP PhpStorm** (чтение, создание, редактирование, рефакторинг). Не редактировать файлы в обход IDE.
- **Проверка документации — через MCP Context7.** Перед использованием API Symfony, Doctrine, ext-sodium и других библиотек сверять сигнатуры и рекомендации с актуальной документацией через Context7 (resolve-library-id → get-library-docs). Не полагаться на память по версиям пакетов.
- Все команды (composer, консоль Symfony, тесты) выполнять внутри docker-контейнеров (см. Makefile, раздел 4).
- Коммиты — атомарные, по этапам из раздела 12, сообщения на английском в стиле conventional commits (`feat:`, `fix:`, `chore:`, `ci:`).

## 3. Технологический стек

- PHP 8.4 (FPM), обязательные расширения: **ext-sodium** (входит в стандартную сборку), **pdo_sqlite**
- Symfony 7.4 LTS (webapp-скелет: Twig, Forms, Validator, Doctrine ORM + Migrations)
- **SQLite** — файл БД в `var/data/app.db` (экономия ресурсов сервера: отдельный контейнер БД не нужен)
- Nginx как веб-сервер
- Docker + Docker Compose (локально и на проде)
- PHPUnit, PHPStan (level 10), PHP-CS-Fixer (@Symfony rules)
- Внешний frontend-фреймворк не используется: Twig-шаблоны + минимальный CSS (можно Pico.css/Water.css из CDN), JS только для кнопки «Скопировать ссылку»

## 4. Локальное docker-окружение

`docker compose up -d` должен поднимать рабочее окружение с нуля.

Сервисы в `compose.yaml`:

- **php** — образ на базе `php:8.4-fpm-alpine`; расширения: pdo_sqlite, intl, opcache, sodium; composer; xdebug (только в dev-образе, режим debug, отключаемый через env `XDEBUG_MODE`)
- **nginx** — `nginx:alpine`, порт `8080:80`, root — `public/`, стандартный конфиг под Symfony (front controller `index.php`)
- Код монтируется volume-ом в php и nginx

БД:

- `DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/app.db"`, каталог `var/data/` в `.gitignore`;
- при инициализации соединения включать `PRAGMA journal_mode=WAL` и `PRAGMA busy_timeout=5000` (конкурентные чтения при записи, для этой нагрузки SQLite более чем достаточно);
- в тестах — отдельный файл `var/data/test.db` или `sqlite:///:memory:`.

Дополнительно:

- `Dockerfile` — multi-stage: `base` (расширения) → `dev` (xdebug, composer) → `prod` (composer install --no-dev --optimize-autoloader, прогретый кэш, opcache включён, без монтирования кода — код копируется в образ)
- `Makefile` с целями: `up`, `down`, `sh` (шелл в php-контейнер), `composer`, `console`, `test`, `stan`, `cs-fix`, `migrate`
- `.env` / `.env.local` по конвенции Symfony; `APP_PASTE_TTL_DAYS=7` — срок жизни записей

## 5. Функциональные требования

### 5.1 Создание записи

- **GET /** — форма: textarea «Текст» (обязательное), input «Подсказка» (необязательное), input «Секретный ключ» (обязательное).
- **POST /** — валидация, шифрование, сохранение, redirect (PRG) на страницу результата.
- **GET /created/{token}** — страница с полной ссылкой вида `https://<host>/p/{token}`, кнопкой «Скопировать» и предупреждением: «Ссылка действует до <дата>. Ключ никому не передаётся через сервер — сообщите его получателю отдельно».

Валидация:
- текст: не пустой, максимум 100 000 символов;
- ключ: не пустой, максимум 255 символов;
- подсказка: максимум 255 символов.

### 5.2 Просмотр записи

- **GET /p/{token}** — если запись существует и не истекла: подсказка (если задана) и форма с полем «Секретный ключ». Если записи нет или она истекла — страница 404 с нейтральным текстом «Запись не найдена или срок её хранения истёк» (не различать эти случаи).
- **POST /p/{token}** — попытка расшифровки:
  - верный ключ → страница с расшифрованным текстом (в `<pre>`, с экранированием) и кнопкой «Скопировать текст»;
  - неверный ключ → та же форма с ошибкой «Неверный ключ» (без деталей). Расшифрованный текст никогда не сохраняется и не кэшируется (заголовки `Cache-Control: no-store` на этой странице).

### 5.3 Токен ссылки

- 16 случайных байт (`random_bytes(16)`), кодировка hex или base62 → строка 22–32 символа;
- уникальный индекс в БД; при коллизии (теоретической) — повторная генерация.

## 6. Модель данных

Единственная сущность `Paste` (типы — Doctrine, маппинг на SQLite штатный):

| Поле | Doctrine-тип | Описание |
|---|---|---|
| id | integer, PK, autoincrement | внутренний id |
| token | string(64), unique index | публичный идентификатор из URL |
| hint | string(255), nullable | подсказка |
| salt | blob | соль для KDF, уникальна для записи |
| nonce | blob | nonce для secretbox |
| ciphertext | blob | зашифрованный текст |
| created_at | datetime_immutable | создано |
| expires_at | datetime_immutable, index | создано + TTL |

Открытый текст, секретный ключ и производный ключ шифрования в БД **не попадают ни в каком виде**.

## 7. Криптография

Реализовать сервис `EncryptionService` (единственное место работы с sodium, покрыт unit-тестами):

**Шифрование** (`encrypt(string $plaintext, string $secret): EncryptedPayload`):
1. `salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES)`;
2. `key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $secret, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13)` — Argon2id, устойчив к перебору ключей;
3. `nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)`;
4. `ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key)` — XSalsa20-Poly1305, аутентифицированное шифрование;
5. `sodium_memzero($key)`; вернуть salt + nonce + ciphertext.

Примечание: MODERATE-лимиты Argon2id заметно нагружают CPU/RAM (~256 МБ на операцию). Для слабого сервера вынести лимиты в env (`APP_KDF_OPSLIMIT`, `APP_KDF_MEMLIMIT`) с дефолтом MODERATE и возможностью понизить до INTERACTIVE (~64 МБ).

**Расшифровка** (`decrypt(EncryptedPayload $payload, string $secret): ?string`):
1. восстановить key через `sodium_crypto_pwhash` с сохранённой солью;
2. `sodium_crypto_secretbox_open(...)`; при `false` (неверный ключ / повреждённые данные) вернуть `null` — вызывающий код показывает «Неверный ключ»;
3. `sodium_memzero($key)`.

Запрещено: логировать секретный ключ и открытый текст (в т.ч. в профилировщик/дампы исключений), использовать самодельные схемы шифрования, хранить hash ключа «для проверки» (проверка корректности ключа — только через Poly1305-аутентификацию secretbox).

## 8. TTL и очистка

- `expires_at = created_at + APP_PASTE_TTL_DAYS`;
- при любом обращении к записи с `expires_at <= now` — 404, как для несуществующей;
- консольная команда `app:paste:purge` — физически удаляет истёкшие записи (`DELETE ... WHERE expires_at <= :now`), выводит количество удалённых; после удаления периодически (например, раз в сутки) выполнять `PRAGMA incremental_vacuum` / `VACUUM`, чтобы файл БД не разрастался;
- запуск по расписанию раз в час через Symfony Scheduler + отдельный контейнер (`php bin/console messenger:consume scheduler_default`) в compose; допустимая альтернатива — cron на хосте, дёргающий команду через `docker compose exec`.

## 9. Нефункциональные требования

- **Rate limiting** (symfony/rate-limiter, storage — cache.app): создание записей — не более 10/мин с IP; попытки расшифровки — не более 10/мин с IP на токен (защита от перебора ключей поверх Argon2id);
- CSRF-защита на обеих формах (штатный механизм Symfony Forms);
- страницы результата расшифровки — `Cache-Control: no-store`;
- интерфейс на русском языке, адаптивная вёрстка (нормально выглядит на телефоне);
- отсутствие каких-либо счётчиков/аналитики/внешних запросов, кроме CDN стилей (по желанию — стили положить локально).

## 10. Тесты и качество

- Unit: `EncryptionService` — encrypt→decrypt roundtrip, неверный ключ → null, разные salt/nonce на каждый вызов, пустой/юникод/большой текст;
- Functional (WebTestCase + SQLite-БД для тестов): создание записи (форма → redirect → страница со ссылкой), просмотр с верным ключом, ошибка при неверном ключе, 404 по несуществующему токену, 404 по истёкшей записи, срабатывание валидации;
- PHPStan level 8 без ошибок, PHP-CS-Fixer без замечаний — оба входят в CI.

## 11. CI/CD (GitHub Actions)

Один workflow `.github/workflows/ci.yml`, три джобы: `test → build → deploy`.

### 11.1 test (на каждый push и pull request)

- `shivammathur/setup-php@v2`: PHP 8.4, расширения `sodium, pdo_sqlite, intl`;
- кэш composer (`actions/cache` по hash `composer.lock`);
- `composer install`, `php-cs-fixer check`, `phpstan`, миграции на тестовой SQLite-БД, `phpunit`.

Отдельный контейнер БД не нужен — SQLite работает прямо в джобе, тесты быстрые.

### 11.2 build (только ветка `main`, needs: test)

- собрать prod-образ из Dockerfile (target `prod`) через `docker/build-push-action`;
- запушить в **GitHub Container Registry**: `ghcr.io/alba84/copypaste`, теги `latest` и `${{ github.sha }}`;
- логин в GHCR — штатным `GITHUB_TOKEN` (`permissions: packages: write` в workflow), отдельных секретов для registry не нужно;
- после первой публикации сделать пакет **публичным** (Settings пакета на GitHub) — тогда серверу не нужна аутентификация для `docker pull`. Если пакет остаётся приватным — однократно на сервере: `docker login ghcr.io` с PAT (scope `read:packages`).

### 11.3 deploy (только `main`, needs: build, environment `production`)

По SSH на сервер (`webfactory/ssh-agent` + обычный `ssh`, либо `appleboy/ssh-action`):

```
cd $DEPLOY_PATH
docker compose -f compose.prod.yaml pull
docker compose -f compose.prod.yaml up -d
docker compose -f compose.prod.yaml exec -T php bin/console doctrine:migrations:migrate -n
docker image prune -f
```

Секреты репозитория (Settings → Secrets and variables → Actions): `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`.

### 11.4 Прод-окружение на сервере

- Предустановка (однократно, вручную): Docker + compose plugin, деплой-пользователь с ключом `DEPLOY_SSH_KEY`, каталог `DEPLOY_PATH` с файлами `compose.prod.yaml` и `.env` (APP_SECRET, TTL, лимиты KDF).
- `compose.prod.yaml`: php (образ из ghcr.io, без volume с кодом), nginx (порт 80/443), scheduler-контейнер для очистки. **Named volume для `var/data/`** (файл SQLite должен переживать пересоздание контейнеров; он же — единственное, что нужно бэкапить). `restart: unless-stopped` у всех.
- HTTPS: если есть домен — добавить Caddy как reverse-proxy с автоматическим Let's Encrypt (вместо голого nginx наружу); если только IP — работать по HTTP с явной пометкой в README.
- Итог: любой push в `main` на GitHub → тесты → сборка образа → автодеплой на сервер за один пайплайн, без ручных действий.

## 12. Этапы работы (порядок коммитов)

1. Каркас: Symfony 7.4 skeleton, docker-окружение, Makefile, README — `docker compose up` показывает стартовую страницу Symfony.
2. Сущность `Paste`, миграция, `EncryptionService` + unit-тесты.
3. Создание записи: форма, контроллер, страница со ссылкой.
4. Просмотр: подсказка, ввод ключа, расшифровка, обработка ошибок, 404.
5. TTL: expires_at, команда purge, scheduler.
6. Rate limiting, полировка UI, functional-тесты.
7. PHPStan/CS-Fixer, prod-target в Dockerfile, `compose.prod.yaml`.
8. `.github/workflows/ci.yml` (test → build → deploy), публикация пакета в GHCR, проверка полного цикла деплоя.

## 13. Definition of Done

- Локально: `make up && make migrate` → сервис работает на `http://localhost:8080`; `make test`, `make stan` — зелёные.
- Сценарий из раздела 1 проходит вручную от начала до конца, включая неверный ключ и истёкшую запись.
- В БД нет ни одного поля с открытым текстом или ключом (проверить, открыв файл `app.db` любым SQLite-клиентом).
- Push в `main` на GitHub автоматически приводит к обновлению сайта на сервере.
- README: запуск локально, переменные окружения, первичная настройка сервера, бэкап файла БД, схема CI/CD.
