# Release Readiness and Rollback Checklist

## Purpose

This checklist is the release control for the modern Mapilio backend and the production clients and services that depend on it. It consolidates repository checks, staging evidence, infrastructure recovery, compatibility validation, rollout, and rollback into one go/no-go decision.

Do not commit a completed production copy of this checklist. Store approvals, deployment identifiers, internal hostnames, account details, logs, screenshots, database measurements, imagery identifiers, and provider evidence in the restricted operational system. Public issues and pull requests must contain only sanitized summaries.

## Evidence Classes

Every checked item must have one of these evidence classes:

- **Automated**: a successful CI job or deterministic command attached to the exact release commit.
- **Restricted**: logs, provider records, screenshots, query plans, restore records, or configuration evidence held outside the public repository.
- **Operator**: a named service owner records an explicit go/no-go decision in the restricted release record.

A checkbox without evidence is not complete. Evidence from a different commit, environment, database snapshot, client build, GeoServer catalog revision, or image-server revision cannot be reused without review.

## Release Record

Create a restricted release record with:

- [ ] UTC release window and change owner
- [ ] backend commit and immutable build artifact digest
- [ ] web and mobile release/build identifiers when affected
- [ ] image-server, anonymizer, AI, GeoServer, database migration, and infrastructure revisions when affected
- [ ] changed feature flags and configuration keys, without values
- [ ] migration direction, estimated duration, lock risk, and rollback boundary
- [ ] approved compatibility scope and intentionally disabled behavior
- [ ] dashboards, alerts, canary cohort, hold duration, and rollback owner
- [ ] previous known-good revisions and artifact digests for every affected service

## Universal Stop Conditions

Stop the release when any of the following is true:

- a required Quality or Secret Scan job is missing, skipped, stale, or failing
- the exact release commit differs from the reviewed and tested commit
- a secret, credential, personal data sample, unblurred imagery, or sensitive infrastructure detail appears in source, logs, artifacts, or public evidence
- backup, PITR, immutable-copy, or isolated restore evidence is missing or outside the approved policy
- a migration cannot be rehearsed against representative staging data or has no proven rollback/forward-fix boundary
- a legacy response contract used by web, mobile, mapilio-kit, AI, OpenStreetMap, or GeoServer changes without an approved versioned migration
- anonymization holdback cannot prevent unblurred originals or stale cache variants from being served
- upload retry, AI callback retry, or queue replay can create duplicate or partial state
- error rate, latency, database load, queue age, cache miss rate, or external dependency failure exceeds the release threshold
- the rollback owner cannot restore the previous known-good revision and configuration

## 1. Repository and Build Gates

- [ ] **Automated:** `Quality / PHP style, analysis, audit, and tests` passes for the exact commit.
- [ ] **Automated:** `Quality / OpenAPI contract, npm audit, and asset build` passes for the exact commit.
- [ ] **Automated:** `Secret Scan / Gitleaks history` passes for the exact commit and complete Git history.
- [ ] **Automated:** the redacted public-content audit passes against the exact candidate tree and complete Git history.
- [ ] **Automated:** `scripts/release/verify-local-readiness.sh` passes in a clean trusted environment using locked dependencies.
- [ ] **Operator:** dependency lockfile changes and GitHub Action SHA changes are explicitly reviewed.
- [ ] **Operator:** the already-visible public security policy and reporting action have passed the documented non-maintainer submission, primary/backup notification, private reply, and non-public closure exercise.
- [ ] **Operator:** release artifacts come from CI or another reproducible trusted builder; a developer working tree is not promoted directly.

The local script is read-only with respect to production and does not run migrations or call external Mapilio services. It starts with the tested contributor doctor, then validates Composer metadata, dependency advisories, formatting, static analysis, Laravel config caching, tests, npm advisories, the pending project-license metadata state, OpenAPI, the asset build, secret scanning, and public-content policy. It does not replace owner provenance review, license selection, staging, or infrastructure evidence.

## 2. Recovery and Database Gates

- [ ] **Restricted/Operator:** `php artisan mapilio:legacy-import-preflight --output=<new-basename>.json --confirm-read-only-source` produces restricted pre-migration evidence only; this gate requires an isolated PostgreSQL/staging run with the owner-approved table allowlist. See [legacy import preflight](../database/legacy-import-preflight.md).
- [ ] **Restricted/Operator:** `php artisan mapilio:fingerprint-import-schema <restricted-descriptor>` deterministically fingerprints separately extracted source and target schema descriptors. The descriptor and digest remain restricted evidence; a synthetic pass does not prove database provenance. See [import schema fingerprint](../database/import-schema-fingerprint.md).
- [ ] **Restricted/Operator:** `php artisan mapilio:extract-import-schema --output=<new-basename>.json --confirm-read-only-source` extracts one configured PostgreSQL source descriptor. The source connection must be explicitly allowlisted and distinct from the application/default connection; output is restricted evidence only and does not prove provenance, mappings, approvals, target compatibility, or importer readiness. See [PostgreSQL import schema descriptor extractor](../database/import-schema-descriptor-extractor.md).
- [ ] **Restricted/Operator:** `php artisan mapilio:extract-target-schema --output=<new-basename>.json --confirm-read-only-target` extracts the canonical target descriptor in local/testing/staging only. Confirm the fixed `pgsql` connection and its normalized endpoint are distinct from `legacy_pgsql`; output remains restricted evidence. See [PostgreSQL target schema descriptor extractor](../database/target-schema-descriptor-extractor.md).
- [ ] **Restricted/Operator:** `php artisan mapilio:validate-import-mapping <restricted-manifest> --source-fingerprint=<actual-source-fingerprint> --target-fingerprint=<actual-target-fingerprint>` validates the owner-approved identity decision manifest before importer design. A synthetic pass does not approve a real mapping. See [identity import mapping validation](../database/identity-import-mapping.md).
- [ ] **Restricted:** infrastructure owners approve current RPO/RTO and evidence-age limits.
- [ ] **Automated:** `php artisan mapilio:verify-backup-readiness` passes against a fresh secret-free evidence manifest before migrations.
- [ ] **Automated:** the digest-pinned disposable PostgreSQL 14/PostGIS Quality job applies, spatially verifies, rolls back, and reapplies every migration.
- [ ] **Restricted:** PostgreSQL/PostGIS backup integrity, encryption with an external key, off-site immutable copy, current WAL/PITR, and isolated restore are verified.
- [ ] **Restricted:** TrueNAS originals/derived imagery, GeoServer configuration, deployment secrets, and durable AI/queue state have independent recovery evidence.
- [ ] **Restricted:** migrations run successfully on a recent staging copy with representative table sizes and PostGIS extensions.
- [ ] **Restricted:** migration lock time, runtime, temporary disk use, index-build method, query plans, and rollback/forward-fix steps are accepted.
- [ ] **Operator:** legacy and modern database cutover points, source-to-target mapping, reconciliation queries, and read-only rollback window are recorded.

Never run the rejected legacy `mysqldump` deployment task. Backup creation and restore authorization remain outside Laravel, as defined in [backup and restore operations](backup-and-restore.md).

## 3. Backend Deployment Gates

- [ ] **Restricted:** production configuration contains an explicit application environment, strong application/auth signing keys, approved trusted proxy CIDRs, database TLS settings, and service-specific credentials from the secret store.
- [ ] **Restricted:** `php artisan config:cache` succeeds using deployment configuration without printing values.
- [ ] **Operator:** all new external side effects and unfinished workflows remain disabled by default.
- [ ] **Operator:** expand-compatible database changes are deployed before code or clients that require them.
- [ ] **Operator:** queue workers and scheduler processes are restarted onto the new immutable release after code and configuration activation.
- [ ] **Restricted:** the validated four-pool worker plan is installed in isolated staging at one process per pool, and crash, graceful restart, redelivery, failed-job retry, shared-lock, and configured failover exercises have recorded evidence.
- [ ] **Restricted:** `/api/v1/system/health` succeeds through the real CDN/reverse-proxy path without exposing dependency details.
- [ ] **Restricted:** normal, validation, authentication, rate-limit, not-found, and server-error responses preserve request IDs, security headers, CORS, ETag, and cache policy.
- [ ] **Restricted:** logs contain only the approved bounded metadata fields and do not contain tokens, bodies, query values, concrete route parameters, IP addresses, or personal data.

## 4. Web and Mobile API Compatibility

- [ ] **Restricted:** sampled legacy and `/api/v1` responses match approved fixtures for changed routes, including status, envelope, null/empty behavior, field types, ordering, pagination, and error shapes.
- [ ] **Restricted:** web password login, refresh rotation, logout/failure cleanup, disabled users, concurrent tabs, protected retries, and rate limiting pass through staging edge infrastructure.
- [ ] **Restricted:** mobile password and refresh authentication budgets are configured and verified across both legacy aliases, including trusted/untrusted forwarded-IP behavior, reset behavior, stable 429 JSON, and `Retry-After` headers.
- [ ] **Restricted:** mobile password login, token refresh after expiry, profile, leaderboard/profile navigation, user uploads/detail, project jobs, image reports, email-modal state, and OneSignal identity verification pass on supported iOS and Android builds.
- [ ] **Restricted:** forgot-password, registration, social login, profile edit/delete, and any still-legacy paths affected by the release have an explicit pass, defer, or block decision.
- [ ] **Restricted:** OpenStreetMap-facing imagery access and any public embed/catalog integrations affected by the release preserve their documented contracts.
- [ ] **Operator:** backend-compatible routes are deployed before clients begin using new versioned aliases.
- [ ] **Operator:** legacy paths remain available until supported client versions have migrated and retirement telemetry is accepted.

Do not record test-user passwords, tokens, emails, request bodies, or production response samples in public release evidence.

## 5. Image Upload, Storage, Cache, and Privacy

- [ ] **Restricted:** the isolated staging image server and disposable NAS/cache paths are confirmed; production and `cdn.mapilio.com` remain blocked from the smoke harness.
- [ ] **Restricted:** `php artisan mapilio:smoke-image-upload --mode=all --confirm-write` passes for mobile multipart upload, mapilio-kit offset/resume/finalization, opaque hash handling, archive extraction, and 480 image serving.
- [ ] **Restricted:** the backend metadata call records idempotent imagery, sequence, point geometry, quality state, and road-line results for both client flows.
- [ ] **Restricted:** malformed images, MIME mismatch, path/ZIP traversal, oversized uploads, interrupted chunks, duplicate retries, stale partials, and quota exhaustion fail safely.
- [ ] **Restricted:** anonymizer success is required before public visibility; failure and backlog states hold imagery rather than exposing originals.
- [ ] **Restricted:** cache keys and invalidation prevent pre-anonymized or stale variants from being served after blur/reprocess/delete.
- [ ] **Restricted:** synthetic smoke artifacts and partial uploads are removed and the cleanup is recorded.
- [ ] **Operator:** the previous image-server contract and backend metadata path remain available for rollback until client and storage evidence is accepted.

## 6. AI Dispatch and Callback Gates

- [ ] **Operator:** prediction dispatch, callback ingestion, result persistence, status projection, and publication flags remain independently disabled unless each affected stage is approved.
- [ ] **Restricted:** staging outbound requests use approved HTTPS endpoints, service authentication, bounded timeouts, idempotency keys, and queue isolation.
- [ ] **Restricted:** callbacks verify HMAC timestamp/nonce/body signatures, replay windows, size/depth/graph limits, response ownership, class codes, sequence imagery ownership, geometry, bbox, and segmentation bounds.
- [ ] **Restricted:** duplicate, reordered, delayed, tampered, partial, oversized, and conflicting callbacks produce no duplicate or partial canonical graph.
- [ ] **Restricted:** error, retry, quarantine, key rotation, expired nonce cleanup, and operator replay procedures are exercised without exposing payloads.
- [ ] **Restricted:** processing-status projection reconciles exactly one request and sequence, and queue replay is idempotent.
- [ ] **Operator:** AI activation starts with a bounded staging/canary cohort and has a flag-only stop path independent of backend rollback.

## 7. GeoServer and Geospatial Gates

- [ ] **Restricted:** authenticated GeoServer catalog, workspace, store, SQL view, layer, style, role, write access, service limits, and cache configuration are reviewed.
- [ ] **Restricted:** the modern PostGIS generated geometry, SRID, validity, GiST index, reconciliation counts, and representative query plans pass in staging.
- [ ] **Restricted:** `mapilio:ai_features_v1` WFS schema, filters, vector tiles, cache behavior, feature-detail IDs, and backend detail API agree on representative data.
- [ ] **Restricted:** existing `features`, `map_points`, `map_roads_line`, and `captured_roads_point` clients remain unchanged unless their migration is explicitly in scope.
- [ ] **Restricted:** the broken `captured_roads_point_v2` dependency and point-tile timeout are resolved or explicitly excluded with visible diagnostics before related cutover.
- [ ] **Restricted:** GeoServer configuration export/rebuild and layer/cache rollback are tested.
- [ ] **Operator:** publication delivery stays disabled until registration, preparation, reconciliation, external delivery, and cache invalidation all have passing evidence.

## 8. Rollout and Observation

- [ ] **Operator:** deploy backend compatibility first, then enable server-side flags, then release web/mobile consumers; do not reverse this dependency order.
- [ ] **Operator:** use a canary or bounded feature-flag cohort with a defined observation period before wider activation.
- [ ] **Restricted:** dashboards cover API 4xx/5xx/429, p50/p95/p99 latency, PostgreSQL load/locks, queue depth/age/failures, AI callback failures, image upload/cache/anonymizer state, and GeoServer errors/timeouts.
- [ ] **Restricted:** alert ownership, thresholds, escalation path, log access, and retention are confirmed before traffic expansion.
- [ ] **Restricted:** compare compatibility response samples, database aggregates, geospatial extents, and external service outcomes against the pre-release baseline.
- [ ] **Operator:** record go, hold, expand, disable-feature, or rollback decisions at each observation checkpoint.

## 9. Rollback

Choose the smallest safe rollback mechanism: disable the new flag, route clients back to the compatible path, restore the previous service artifact, or execute the reviewed database recovery/forward-fix plan. Do not automatically reverse a migration that may discard or reinterpret writes.

- [ ] **Operator:** stop traffic expansion and disable affected side-effect flags.
- [ ] **Operator:** pause or isolate workers that can continue producing incompatible writes; preserve queue and callback evidence.
- [ ] **Operator:** restore previous backend/client/service artifacts and configuration from recorded immutable revisions.
- [ ] **Restricted:** verify health, auth, critical reads, upload holdback, queues, database integrity, GeoServer layers, and image serving after rollback.
- [ ] **Restricted:** reconcile writes accepted during the release window before replay, backfill, or deletion.
- [ ] **Operator:** follow the incident runbook when confidentiality, integrity, availability, privacy, or credential exposure is involved.
- [ ] **Operator:** record sanitized outcome, owner, and corrective work; do not mark the release complete while recovery tasks are unowned.

## Final Decision

The release owner records exactly one result in restricted operational evidence:

- **GO**: every required gate for the change scope has current evidence and owners approve rollout.
- **HOLD**: no production change; evidence or an external dependency is incomplete.
- **LIMITED GO**: only the documented canary/flag scope is approved, with a fixed observation checkpoint.
- **ROLLBACK**: rollout stopped and the rollback section executed.

Repository checks prove source quality and reproducibility. They do not prove production backup health, client compatibility, imagery privacy, AI integrity, GeoServer readiness, or operational ownership; those remain fail-closed release gates.
