#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

wait_for() {
    local host="$1" port="$2" name="$3" retries=60
    echo "Waiting for ${name} at ${host}:${port}..."
    until nc -z "${host}" "${port}" 2>/dev/null || [ "${retries}" -le 0 ]; do
        retries=$((retries - 1))
        sleep 2
    done
    if [ "${retries}" -le 0 ]; then
        echo "WARNING: ${name} never became reachable, continuing anyway."
    else
        echo "${name} is up."
    fi
}

# 1. .env - the compose file already injects the real values as env vars,
#    this only guarantees the file exists for artisan.
if [ ! -f .env ]; then
    echo "Creating .env from .env.example"
    cp .env.example .env
fi

# 2. Dependencies. Vendor lives in the bind mount, so this only runs once.
if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    echo "Installing composer dependencies (first boot, this takes a while)..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# 3. App key
if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

chmod -R ug+rw storage bootstrap/cache || true

wait_for "${DB_HOST:-postgres}" "${DB_PORT:-5432}" "PostgreSQL"

# 4. Schema. Idempotent: already-applied migrations are skipped.
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    php artisan migrate --force
fi

# 5. Seed once, so a fresh clone has data to search immediately.
if [ "${AUTO_SEED:-true}" = "true" ]; then
    APIS_COUNT="$(php artisan apis:count 2>/dev/null | tr -dc '0-9')"
    if [ -z "${APIS_COUNT}" ] || [ "${APIS_COUNT}" = "0" ]; then
        echo "Seeding starter dataset..."
        php artisan db:seed --force
    fi
fi

wait_for "${OPENSEARCH_HOST:-opensearch}" "${OPENSEARCH_PORT:-9200}" "OpenSearch"

# 6. Build the search index if it is empty.
if [ "${AUTO_INDEX:-true}" = "true" ]; then
    if ! php artisan search:status >/dev/null 2>&1; then
        echo "Building search index..."
        php artisan search:reindex || echo "WARNING: reindex failed, run it manually later."
    fi
fi

php artisan config:clear >/dev/null 2>&1 || true

echo "Backend ready."
exec "$@"
