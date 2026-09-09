#!/usr/bin/env bash

set -Eeuo pipefail

readonly RELEASE_TAG="${1:-}"
readonly APP_DIR="${APP_DIR:-/opt/copypaste}"
readonly BACKUP_DIR="${BACKUP_DIR:-${APP_DIR}/backups}"
readonly COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-copypaste}"
readonly COMPOSE_FILE="${APP_DIR}/compose.prod.yaml"
readonly TAG_FILE="${APP_DIR}/.deployed-tag"
PREVIOUS_TAG=""

if [[ ! "${RELEASE_TAG}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    printf 'Invalid release tag: %s\n' "${RELEASE_TAG}" >&2
    exit 2
fi

cd "${APP_DIR}"
mkdir -p "${BACKUP_DIR}"

if [[ -f "${TAG_FILE}" ]]; then
    PREVIOUS_TAG="$(<"${TAG_FILE}")"
fi

export IMAGE_TAG="${RELEASE_TAG}"
export COMPOSE_PROJECT_NAME

rollback() {
    local exit_code=$?
    trap - ERR

    if [[ "${PREVIOUS_TAG}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
        printf 'Deployment failed; restarting previous release %s.\n' "${PREVIOUS_TAG}" >&2
        export IMAGE_TAG="${PREVIOUS_TAG}"
        docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans || true
    else
        printf 'Deployment failed and no previous release is recorded.\n' >&2
    fi

    exit "${exit_code}"
}

docker compose -f "${COMPOSE_FILE}" pull
trap rollback ERR

docker compose -f "${COMPOSE_FILE}" stop nginx scheduler php || true

readonly BACKUP_NAME="sqlite-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
docker run --rm \
    --user 0 \
    --entrypoint sh \
    --volume "${COMPOSE_PROJECT_NAME}_paste_data:/data:ro" \
    --volume "${BACKUP_DIR}:/backup" \
    "ghcr.io/alba84/copypaste:${RELEASE_TAG}" \
    -c "if [ -f /data/app.db ]; then tar -czf '/backup/${BACKUP_NAME}' -C /data app.db app.db-wal app.db-shm 2>/dev/null || tar -czf '/backup/${BACKUP_NAME}' -C /data app.db; fi"

docker compose -f "${COMPOSE_FILE}" run --rm --no-deps php \
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans

for attempt in {1..12}; do
    if docker compose -f "${COMPOSE_FILE}" exec -T nginx wget --quiet --tries=1 --spider http://127.0.0.1/; then
        printf '%s\n' "${RELEASE_TAG}" > "${TAG_FILE}"
        find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'sqlite-*.tar.gz' -mtime +14 -delete
        docker image prune --force --filter 'until=720h'
        trap - ERR
        printf 'Release %s deployed successfully.\n' "${RELEASE_TAG}"
        exit 0
    fi

    sleep 5
done

docker compose -f "${COMPOSE_FILE}" ps >&2
docker compose -f "${COMPOSE_FILE}" logs --tail=100 >&2
printf 'Release %s failed its health check.\n' "${RELEASE_TAG}" >&2
false
