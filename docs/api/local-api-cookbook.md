# Synthetic Local API Cookbook

Use this cookbook after completing the [disposable local SQLite setup](../operations/local-development.md), including its [contributor platform matrix](../operations/contributor-platform-matrix.md), and starting Laravel. The commands below are read-only `GET` requests to the loopback server only. Never replace `127.0.0.1` with a remote host.

The local setup requires `MAPILIO_DEMO_SEEDING_ENABLED=true`. `php artisan migrate:fresh` is destructive: it drops every table on the selected connection. Use it only for the explicitly enabled disposable local SQLite fixture described in the local-development guide; never use it against a remote, shared, staging, or production connection.

## Health check

Run the core request without any formatter:

```bash
curl --fail-with-body --silent --show-error \
  http://127.0.0.1:8000/api/v1/system/health
```

The expected HTTP status is `200`. The top-level JSON object contains `status`, `service`, `api_version`, `compatibility`, and `timestamp`. Safe fields to recognize include `status: "ok"`, `service: "mapilio-modern-backend"`, `api_version: "v1"`, and `compatibility: "legacy-v1-behavior"`; `timestamp` varies per response.

Optionally, use `jq` to display only those stable fields:

```bash
set -o pipefail
curl --fail-with-body --silent --show-error \
  http://127.0.0.1:8000/api/v1/system/health \
  | jq '{status, service, api_version, compatibility}'
```

## Seeded feature detail

The local fixture exposes one synthetic feature. Fetch it without `jq`:

```bash
curl --fail-with-body --silent --show-error \
  http://127.0.0.1:8000/api/v1/geo/ai-features/900000001
```

The expected HTTP status is `200`. The top-level JSON object has a `data` member. In the seeded response, `data` is a GeoJSON `Feature` with an integer `id`, `geometry` whose `type` is `Point`, `properties` containing `class_code` and an `attributes.demo` flag, and a `matches` array. The seeded fixture has no users or credentials, no imagery matches, and no externally connected data.

Optionally, project only safe summary fields with `jq`. This view intentionally excludes coordinates and all nested match or imagery details:

```bash
set -o pipefail
curl --fail-with-body --silent --show-error \
  http://127.0.0.1:8000/api/v1/geo/ai-features/900000001 \
  | jq '.data | {
      id,
      feature_type: .type,
      geometry_type: .geometry.type,
      class_code: .properties.class_code,
      demo: .properties.attributes.demo,
      match_count: (.matches | length)
    }'
```

## Troubleshooting

- **404:** Confirm Laravel is running on `127.0.0.1:8000`, the `/api/v1` prefix is present, and the feature id is `900000001`.
- **500:** Confirm the disposable SQLite path exists, the configured connection is SQLite, and the schema was rebuilt and seeded locally.
- **Configuration cache:** After changing `.env`, run `php artisan config:clear`, then rebuild and seed the disposable SQLite fixture if needed.
- **Seed flag:** Confirm `MAPILIO_DEMO_SEEDING_ENABLED=true` in the local environment. The seeder fails closed when the flag is absent or disabled.
