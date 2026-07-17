# Local Development

This workflow creates a disposable SQLite database containing synthetic modern-schema data. It does not connect to the legacy or production database.

## Requirements

- PHP 8.2 or newer supported by the locked dependencies
- Composer
- Node.js and npm for frontend asset verification
- PHP SQLite extension

Install the locked dependencies and create local configuration:

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

Use an absolute SQLite path in `.env`:

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/mapilio-modern-backend/database/database.sqlite
MAPILIO_LEGACY_DB_CONNECTION=sqlite
MAPILIO_DEMO_SEEDING_ENABLED=true
```

The demo feature has no legacy imagery observations, so browsing it does not require PyroCMS tables.

## Rebuild And Seed

`migrate:fresh` destroys every table on the selected connection. Run it only against the disposable SQLite file above.

```bash
php artisan config:clear
php artisan migrate:fresh --seed
php artisan serve
```

Open:

```text
http://127.0.0.1:8000/api/v1/geo/ai-features/900000001
```

The seed creates one encrypted callback receipt and one canonical AI feature. Running `php artisan db:seed` again updates the same records instead of duplicating them. It creates no user or default password; the new dashboard and its authentication will be implemented only for approved workflows.

## Safety Boundary

The seeder refuses to run unless all of these are true:

- demo seeding is explicitly enabled;
- `APP_ENV` is `local` or `testing`;
- the active database driver is SQLite.

It therefore rejects PostgreSQL, staging, and production even if the feature flag is accidentally enabled. Keep `MAPILIO_DEMO_SEEDING_ENABLED=false` whenever inspecting a remote read-only database, and never run migrations against that connection.

## Verification

```bash
php artisan test tests/Unit/LocalDemoSeedGuardTest.php
php artisan test tests/Feature/DatabaseMigrationAndDemoSeedTest.php
scripts/release/verify-local-readiness.sh
```

The migration suite uses in-memory SQLite. Before release, run the migrations separately against a disposable PostgreSQL 14/PostGIS environment and retain sanitized evidence outside the public repository.
