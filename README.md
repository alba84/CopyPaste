# CopyPaste
Анонимный сервис передачи текста по ссылке. Текст хранится только в зашифрованном виде (Argon2id + sodium secretbox), ключ сервером не сохраняется.

## Локальный запуск
Требуются Docker Desktop и Compose.
```bash
make up
make migrate
```
Откройте http://localhost:8080. Проверки: `make test`, `make stan`, `make cs-fix`.

## Настройки
Скопируйте локальные секреты в `.env.local`: `APP_SECRET`, `APP_PASTE_TTL_DAYS`, `APP_KDF_OPSLIMIT`, `APP_KDF_MEMLIMIT`, `DATABASE_URL`. По умолчанию KDF использует MODERATE (3 операции, 256 MiB); для слабого узла допустимы INTERACTIVE (2, 67108864).

SQLite хранится в `var/data/app.db`; включаются WAL и busy timeout. Бэкап состоит из файла БД вместе с `-wal`/ `-shm` при работающем сервисе, либо одного app.db после корректной остановки.

## Production и CI/CD
На сервере установите Docker, положите `compose.prod.yaml`, `docker/nginx/default.conf` и production `.env` в `DEPLOY_PATH`. Для домена рекомендуется Caddy перед nginx с автоматическим HTTPS; при IP сервис работает по HTTP. Создайте GitHub secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`. Workflow тестирует проект, публикует `ghcr.io/alba84/copypaste` и разворачивает main. Сделайте GHCR-пакет публичным либо выполните на сервере `docker login ghcr.io`.
