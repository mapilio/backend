# Disposable PostGIS Migration Gate

GitHub Actions runs this gate automatically as the `PostgreSQL 14 and PostGIS migrations` Quality job. It creates an ephemeral service database and destroys it with the runner.

## Optional Local Run

Use this only with a local Docker daemon and an unused local port `5432`. The container has no persistent volume and uses trust authentication only on the loopback-published test port.

```bash
docker run --rm --detach \
  --name mapilio-postgis-ci \
  --publish 127.0.0.1:5432:5432 \
  --env POSTGRES_DB=mapilio_ci \
  --env POSTGRES_USER=mapilio_ci \
  --env POSTGRES_HOST_AUTH_METHOD=trust \
  postgis/postgis:14-3.5@sha256:46b46bcd0ed1a60adceacbcb5116f3370f44608d50b5cc5935849872b762c0ab
```

Wait for readiness:

```bash
until docker exec mapilio-postgis-ci pg_isready -U mapilio_ci -d mapilio_ci; do sleep 1; done
```

Run the guarded test:

```bash
APP_ENV=testing \
DB_CONNECTION=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_DATABASE=mapilio_ci \
DB_USERNAME=mapilio_ci \
DB_SSLMODE=disable \
DB_URL= \
MAPILIO_DISPOSABLE_DB_CONFIRMED=true \
MAPILIO_LEGACY_DB_CONNECTION=sqlite \
MAPILIO_DEMO_SEEDING_ENABLED=false \
scripts/database/verify-postgis-migrations.sh
```

Remove the container:

```bash
docker stop mapilio-postgis-ci
```

The wrapper intentionally does not accept a remote host, alternate database name, URL override, staging environment, or production environment. Do not weaken those checks to target a convenient shared database.

## Remaining Staging Evidence

Before a release containing migrations, rehearse them separately on an isolated recent staging copy. Record sanitized evidence for runtime, locks, temporary disk, representative table sizes, PostGIS version, spatial validity, index definitions, query plans, forward-fix/rollback boundaries, and backup/restore readiness. Do not commit connection details or completed operational evidence to the public repository.
