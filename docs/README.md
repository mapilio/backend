# Documentation

## Start Here

- [Project status](project-status.md)
- [Project roadmap](../ROADMAP.md)
- [Ecosystem architecture](architecture/ecosystem.md)
- [Domain boundaries](../app/Domain/README.md)
- [Modern OpenAPI contract](api/openapi-v1.json)
- [Public API message compatibility and localization readiness](api/public-message-compatibility.md)
- [Synthetic local API cookbook](api/local-api-cookbook.md)
- [Synthetic GeoJSON fixtures and local validator](geospatial/synthetic-geojson-fixtures.md)
- [Generated API reference](../public/docs/api/index.html)
- [Local development](operations/local-development.md)
- [Contributor platform matrix and doctor](operations/contributor-platform-matrix.md)
- [Contributing](../CONTRIBUTING.md)

## Integrations

- [OpenStreetMap-facing backend contract map](integrations/openstreetmap-facing-contract.md) — implemented draft; API/geospatial owner review required; non-publishable

## Architecture Decisions

- [0001: Modern backend foundation](architecture/0001-modern-backend-foundation.md)
- [0002: Database modernization strategy](architecture/0002-database-modernization-strategy.md)
- [0003: Legacy compatibility endpoints](architecture/0003-legacy-compatibility-endpoints.md)
- [0004: Unsupported legacy surface guardrails](architecture/0004-unsupported-legacy-surface-guardrails.md)
- [0005: Image upload and serving contract](architecture/0005-image-upload-and-serving-contract.md)
- [0006: Post-upload quality scoring](architecture/0006-post-upload-quality-scoring.md)
- [0007: AI prediction dispatch boundary](architecture/0007-ai-prediction-dispatch-boundary.md)
- [0008: Signed AI callback receipts](architecture/0008-signed-ai-callback-receipts.md)
- [0009: Canonical AI detection persistence](architecture/0009-canonical-ai-detection-persistence.md)
- [0010: Sequence address enrichment](architecture/0010-sequence-address-enrichment.md)
- [0011: UKM scoring boundary](architecture/0011-ukm-scoring-boundary.md)
- [0012: AI completion and Geo publication outbox](architecture/0012-ai-completion-and-geo-publication-outbox.md)
- [0013: Versioned AI geospatial projection](architecture/0013-versioned-ai-geospatial-projection.md)
- [0014: Versioned AI feature-detail API](architecture/0014-versioned-ai-feature-detail-api.md)
- [0015: Server-side newsletter subscriptions](architecture/0015-server-side-newsletter-subscriptions.md)
- [0016: First-party web auth boundary](architecture/0016-first-party-web-auth-boundary.md)
- [0017: Explicit trusted proxy boundary](architecture/0017-explicit-trusted-proxy-boundary.md)
- [0018: API edge observability boundary](architecture/0018-api-edge-observability-boundary.md)
- [0019: Staging image-upload contract smoke](architecture/0019-staging-image-upload-contract-smoke.md)
- [0020: Backup readiness evidence gate](architecture/0020-backup-readiness-evidence-gate.md)
- [0021: Baseline-free CI quality gates](architecture/0021-baseline-free-ci-quality-gates.md)
- [0022: Queue runtime safety](architecture/0022-queue-runtime-safety.md)
- [0023: Fail-closed local demo data](architecture/0023-fail-closed-local-demo-data.md)
- [0024: Disposable PostGIS migration gate](architecture/0024-disposable-postgis-migration-gate.md)
- [0025: Generated API reference](architecture/0025-generated-api-reference.md)
- [0026: Public-content release gate](architecture/0026-public-content-release-gate.md)
- [0027: Pending license-state gate](architecture/0027-pending-license-state-gate.md)
- [0028: Legacy import preflight evidence](architecture/0028-legacy-import-preflight.md)
- [0029: Identity import mapping contract](architecture/0029-identity-import-mapping-contract.md)
- [0030: Import schema fingerprint contract](architecture/0030-import-schema-fingerprint-contract.md)
- [0031: PostgreSQL import schema descriptor extraction](architecture/0031-postgresql-import-schema-descriptor-extraction.md)

## Database

- [Target schema draft](database/target-schema-draft.md)
- [Legacy usage audit summary](database/legacy-usage-audit-summary.md)
- [UKM PostGIS index plan](database/ukm-postgis-index-plan.md)
- [Legacy import preflight](database/legacy-import-preflight.md)
- [Identity import mapping validation](database/identity-import-mapping.md)
- [Identity import mapping schema](database/identity-import-mapping.schema.json)
- [Import schema fingerprint](database/import-schema-fingerprint.md)
- [PostgreSQL import schema descriptor extractor](database/import-schema-descriptor-extractor.md)
- [Import schema fingerprint schema](database/import-schema-fingerprint.schema.json)

## Operations

- [Runtime and deployment](operations/runtime-and-deployment.md)
- [Contributor platform matrix and doctor](operations/contributor-platform-matrix.md)
- [Release readiness](operations/release-readiness.md)
- [Backup and restore](operations/backup-and-restore.md)
- [Disposable PostGIS migration gate](operations/postgis-migration-gate.md)
- [Scheduled jobs and geospatial summary](operations/scheduled-jobs-and-geospatial-summary.md)

## Security And Governance

- [Security policy](../SECURITY.md)
- [Secret management](security/secret-management.md)
- [Public-content audit](security/public-content-audit.md)
- [Incident response](security/incident-response.md)
- [Public-release decisions](governance/public-release-decisions.md)

## Community

- [Issue triage policy](community/issue-triage.md)
- [Initial community issue catalog](community/initial-issue-catalog.md)
