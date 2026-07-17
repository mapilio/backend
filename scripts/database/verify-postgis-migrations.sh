#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "PostGIS migration gate refused to run: $1" >&2
    exit 2
}

[[ ${MAPILIO_DISPOSABLE_DB_CONFIRMED:-} == 'true' ]] || fail 'disposable database confirmation is missing.'
[[ ${APP_ENV:-} == 'testing' ]] || fail 'APP_ENV must be testing.'
[[ ${DB_CONNECTION:-} == 'pgsql' ]] || fail 'DB_CONNECTION must be pgsql.'
[[ ${DB_HOST:-} == '127.0.0.1' ]] || fail 'DB_HOST must be 127.0.0.1.'
[[ ${DB_PORT:-} == '5432' ]] || fail 'DB_PORT must be 5432.'
[[ ${DB_DATABASE:-} == 'mapilio_ci' ]] || fail 'DB_DATABASE must be mapilio_ci.'
[[ ${DB_USERNAME:-} == 'mapilio_ci' ]] || fail 'DB_USERNAME must be mapilio_ci.'
[[ ${DB_SSLMODE:-} == 'disable' ]] || fail 'DB_SSLMODE must be disable for the local disposable service.'
[[ -z ${DB_URL:-} ]] || fail 'DB_URL must be empty so it cannot override the guarded connection.'
[[ ${MAPILIO_LEGACY_DB_CONNECTION:-} == 'sqlite' ]] || fail 'the legacy database connection must remain isolated.'
[[ ${MAPILIO_DEMO_SEEDING_ENABLED:-} != 'true' ]] || fail 'SQLite-only demo seeding must remain disabled.'

repository_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "${repository_root}"

php artisan test tests/Integration/PostgisMigrationTest.php --stop-on-failure
