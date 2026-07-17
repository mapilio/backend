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
- Signed, replay-protected AI callback receipts are documented in `docs/architecture/0008-signed-ai-callback-receipts.md`.
- Strict, transactional AI detection-result persistence is documented in `docs/architecture/0009-canonical-ai-detection-persistence.md`.
- Disabled-by-default sequence address enrichment is documented in `docs/architecture/0010-sequence-address-enrichment.md`.
- Disabled-by-default UKM scoring and its PostGIS index gate are documented in `docs/architecture/0011-ukm-scoring-boundary.md`.
- Disabled-by-default AI completion projection and blocked Geo publication outbox are documented in `docs/architecture/0012-ai-completion-and-geo-publication-outbox.md`.
- Versioned PostGIS AI feature projection and publication reconciliation are documented in `docs/architecture/0013-versioned-ai-geospatial-projection.md`.
- Public versioned AI feature detail with bounded graph reads and imagery metadata is available at `/api/v1/geo/ai-features/{featureId}` and documented in `docs/architecture/0014-versioned-ai-feature-detail-api.md`.
- Public newsletter requests are proxied server-to-server at `POST /api/v1/content/newsletter-subscriptions`; provider credentials never belong in the web bundle. See `docs/architecture/0015-server-side-newsletter-subscriptions.md`.
- First-party password and refresh grants are available to the public web client without a browser-exposed confidential client secret at `POST /api/v1/web/auth/token`. See `docs/architecture/0016-first-party-web-auth-boundary.md`.
- Forwarded client IPs are accepted only from an explicit IP/CIDR allowlist; wildcard proxy trust is rejected. See `docs/architecture/0017-explicit-trusted-proxy-boundary.md`.
- API security headers, backend-generated request IDs, and opt-in metadata-only request logs are documented in `docs/architecture/0018-api-edge-observability-boundary.md`.
- A production-blocked staging smoke command verifies mobile and mapilio-kit upload, resume, hash, and image-serving contracts. See `docs/architecture/0019-staging-image-upload-contract-smoke.md`.
- Full-history secret scanning is enforced by a pinned, checksum-verified Gitleaks workflow and documented in `docs/security/secret-management.md`.
- Responsible disclosure and private reporting rules are defined in `SECURITY.md`; public release remains blocked until GitHub private vulnerability reporting is enabled and verified.
- Security incident roles, severity, ecosystem playbooks, evidence handling, recovery, and post-incident review are documented in `docs/security/incident-response.md`.
- A strict, read-only backup evidence gate validates encryption, integrity, off-site immutability, PITR/WAL freshness, isolated restore drills, and measured recovery objectives without owning backup credentials. See `docs/architecture/0020-backup-readiness-evidence-gate.md`.
- GitHub Actions enforces locked dependency audits, Pint, baseline-free Larastan level 5, Laravel configuration caching, the full Laravel suite, strict OpenAPI lint, and the production asset build with commit-pinned setup actions. See `docs/architecture/0021-baseline-free-ci-quality-gates.md`.
- The ecosystem release control consolidates automated repository checks, staging evidence, compatibility, privacy, rollout, and rollback for backend, clients, image services, AI, and GeoServer. See `docs/operations/release-readiness.md`.
- Async queue connections now fail closed unless their retry/visibility window safely exceeds every declared job timeout; worker topology and deployment operations are documented in `docs/operations/runtime-and-deployment.md` and ADR 0022.
- Local demo data now fails closed outside explicitly enabled local/testing SQLite, creates no account or legacy table, and is covered by migration apply/rollback plus versioned API tests. See `docs/operations/local-development.md` and ADR 0023.
- The modern API contract starts at `docs/api/openapi-v1.json`; the separate legacy compatibility inventory remains a migration input.
- Domain notes: `app/Domain/README.md`.
- Architecture decision records:
  - `docs/architecture/0001-modern-backend-foundation.md`
  - `docs/architecture/0002-database-modernization-strategy.md`
  - `docs/architecture/0003-legacy-compatibility-endpoints.md`
  - `docs/architecture/0004-unsupported-legacy-surface-guardrails.md`
  - `docs/architecture/0005-image-upload-and-serving-contract.md`
  - `docs/architecture/0006-post-upload-quality-scoring.md`
  - `docs/architecture/0007-ai-prediction-dispatch-boundary.md`
  - `docs/architecture/0008-signed-ai-callback-receipts.md`
  - `docs/architecture/0009-canonical-ai-detection-persistence.md`
  - `docs/architecture/0010-sequence-address-enrichment.md`
  - `docs/architecture/0011-ukm-scoring-boundary.md`
  - `docs/architecture/0012-ai-completion-and-geo-publication-outbox.md`
  - `docs/architecture/0013-versioned-ai-geospatial-projection.md`
  - `docs/architecture/0014-versioned-ai-feature-detail-api.md`
  - `docs/architecture/0015-server-side-newsletter-subscriptions.md`
  - `docs/architecture/0016-first-party-web-auth-boundary.md`
  - `docs/architecture/0017-explicit-trusted-proxy-boundary.md`
  - `docs/architecture/0018-api-edge-observability-boundary.md`
  - `docs/architecture/0019-staging-image-upload-contract-smoke.md`
  - `docs/architecture/0020-backup-readiness-evidence-gate.md`
  - `docs/architecture/0021-baseline-free-ci-quality-gates.md`
  - `docs/architecture/0022-queue-runtime-safety.md`
  - `docs/architecture/0023-fail-closed-local-demo-data.md`
- Database design draft: `docs/database/target-schema-draft.md`.
- UKM PostGIS index plan: `docs/database/ukm-postgis-index-plan.md`.
- Legacy usage audit summary: `docs/database/legacy-usage-audit-summary.md`.
- Scheduled jobs and geospatial summary: `docs/operations/scheduled-jobs-and-geospatial-summary.md`.
- Release readiness and rollback checklist: `docs/operations/release-readiness.md`.
- Runtime and deployment operations: `docs/operations/runtime-and-deployment.md`.
- Safe local database setup and synthetic fixtures: `docs/operations/local-development.md`.

## Local Commands

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan test
php artisan serve
scripts/security/scan-secrets.sh
php artisan mapilio:smoke-image-upload --mode=all --confirm-write
php artisan mapilio:verify-backup-readiness
composer format:test
composer analyse
npm run lint:openapi
scripts/release/verify-local-readiness.sh
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
