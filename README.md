# Mapilio Modern Backend

Clean Laravel backend foundation for the Mapilio platform migration.

This project does not port PyroCMS module structure one-to-one. It preserves external contracts where active clients depend on them, then rebuilds internals around Mapilio domains.

## Current Foundation

- Laravel Framework 12.x on local PHP 8.2.
- Composer selected Laravel 12 because Laravel 13 requires PHP 8.3.
- API prefix: `/api/v1`.
- First health endpoint: `/api/v1/system/health`.
- First preserved legacy endpoint: `/api/country-image-count`.
- Versioned alias for the first preserved endpoint: `/api/v1/imagery/country-image-count`.
- Preserved leaderboard endpoints: `/api/leaderboard` and `/api/get-point-by-user`.
- Versioned aliases for leaderboard reads: `/api/v1/imagery/leaderboard` and `/api/v1/imagery/user-points`.
- Preserved leaderboard winner endpoints: `/api/leaderboard-winner` and `/api/v2/leaderboard-winner`.
- Versioned alias for leaderboard winner metadata: `/api/v1/imagery/leaderboard-winner`.
- Preserved sequence metadata endpoint: `/api/sequence-detail`.
- Versioned alias for sequence metadata: `/api/v1/imagery/sequence-detail`.
- Preserved embed image endpoint: `/api/embed/{sequenceUuid}`.
- Versioned alias for embed image metadata: `/api/v1/imagery/embed/{sequenceUuid}`.
- Preserved uploaded road geometry endpoint: `/api/get-uploaded-roads-group`.
- Versioned alias for uploaded road geometry: `/api/v1/geo/uploaded-roads-group`.
- Preserved upload metadata endpoint: `/api/function/mapilio/imagery/upload`.
- Versioned alias for upload metadata: `/api/v1/imagery/uploads`.
- Image server contract is documented in `docs/architecture/0005-image-upload-and-serving-contract.md`.
- Post-upload GPS, capture-time, and sequence-distance scoring is documented in `docs/architecture/0006-post-upload-quality-scoring.md`.
- The disabled-by-default AI prediction dispatch boundary is documented in `docs/architecture/0007-ai-prediction-dispatch-boundary.md`.
- Domain notes: `app/Domain/README.md`.
- Architecture decision records:
  - `docs/architecture/0001-modern-backend-foundation.md`
  - `docs/architecture/0002-database-modernization-strategy.md`
  - `docs/architecture/0003-legacy-compatibility-endpoints.md`
  - `docs/architecture/0004-unsupported-legacy-surface-guardrails.md`
  - `docs/architecture/0005-image-upload-and-serving-contract.md`
  - `docs/architecture/0006-post-upload-quality-scoring.md`
  - `docs/architecture/0007-ai-prediction-dispatch-boundary.md`
- Database design draft: `docs/database/target-schema-draft.md`.
- Legacy usage audit summary: `docs/database/legacy-usage-audit-summary.md`.
- Scheduled jobs and geospatial summary: `docs/operations/scheduled-jobs-and-geospatial-summary.md`.

## Local Commands

```bash
composer install
php artisan test
php artisan serve
```

## Migration Rule

Legacy compatibility means public behavior compatibility, not implementation compatibility.

Old PyroCMS modules can be:

- rewritten as Laravel domain code
- replaced with maintained packages
- extracted into services
- archived for data access
- retired when unused

The old backend remains the source of truth for current behavior until contract tests and owner-reviewed decisions replace assumptions.

## Legacy Compatibility Endpoints

Legacy routes remain available when current web, mobile, AI, or community integrations depend on them. Each preserved route should have:

- an explicit compatibility controller under `app/Http/Controllers/Legacy`
- domain/query code outside the controller
- a versioned `/api/v1` alias for future documentation
- feature tests that verify response shape, field types, and key error envelopes
- live smoke verification against a safe read-only legacy database or staging copy before release

## Unsupported Legacy Surfaces

The modern backend does not expose the old dynamic dispatcher, generic entry reads, dynamic auth login/register, body-token dynamic APIs, public write routes, or AI callback route until each surface has an explicit compatibility and security design.

Unsupported API and webhook paths return a stable JSON 404 response without debug stack traces.

## Database Modernization Rule

The new backend may use a redesigned PostgreSQL/PostGIS schema. The legacy database is the source of truth during migration, but old table shapes are not automatically preserved.

Any schema rewrite must be protected by repeatable import commands, source-to-target ID mapping, staging backfills, aggregate checks, geospatial checks, API contract tests, and a route-by-route cutover plan.
