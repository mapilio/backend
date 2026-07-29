# Project Status

## Current State

The modern backend is a pre-release migration target. It runs independently as Laravel, but production cutover is blocked until representative staging, ecosystem compatibility, recovery, privacy, and operator evidence pass.

Implemented foundations include:

- Laravel domain boundaries without a PyroCMS runtime dependency;
- legacy compatibility controllers and versioned aliases for active web/mobile reads and writes already audited;
- mobile and first-party web token boundaries;
- mobile/mapilio-kit imagery metadata compatibility and image URL generation;
- signed AI callbacks, encrypted receipts, canonical result persistence, status projection, and publication outboxes;
- versioned PostGIS AI projection and public AI feature detail;
- sequence quality, address enrichment, and UKM scoring boundaries;
- request IDs, security headers, trusted proxies, bounded metadata-only request events, and rate limits;
- backup/readiness, queue runtime, secret scanning, dependency audits, static analysis, strict OpenAPI, a self-contained generated API reference, SQLite migrations, and disposable PostgreSQL/PostGIS gates.
- contributor issue forms, a safety-bounded triage policy, and eight sanitized initial community issue drafts awaiting owner-controlled publication.
- full-history secret scanning plus a redacted public-content policy gate for private network/hostname patterns, personal emails, local paths, risky artifacts, and unapproved platform hosts.
- a tested package/OpenAPI metadata gate that prevents an open-source license claim while license selection remains an owner decision.
- a read-only contributor environment doctor plus macOS, Ubuntu/WSL, CI, PHP-extension, and optional PostGIS tooling matrix.
- validated synthetic request/response examples for every schema-bearing JSON media type in the three documented modern operations, with focused coverage checks and generated API documentation.
- deterministic relative-link checking for tracked Markdown, with focused unit coverage and local/Quality gate integration.
- a synthetic local API cookbook covering the health and seeded feature-detail `GET` checks; an isolated local SQLite smoke was verified on loopback port `18000` with shell environment overrides, response assertions, and cleanup, and the complete local release gate passed with 257 tests and 1,116 assertions.
- a test-only legacy mobile-auth fixture helper shared by the email-modal and project-job compatibility suites; exact synthetic account rows and all 55 focused assertions are preserved, and the complete local release gate passed with 257 tests and 1,116 assertions.
- a reusable test-only query-budget assertion with explicit connection/table scopes and isolated listener lifecycle; six helper regressions protect exact budgets, unrelated-query exclusion, dispatcher restoration, and callback behavior while the AI feature-detail contract remains exactly four bounded graph reads, and the complete local release gate passed with 263 tests and 1,143 assertions.
- a repository-evidence inventory of public API messages and internal error boundaries, including exact route/status/envelope compatibility, backend assertion strength, future stable-code recommendations, privacy-safe exclusions, and unresolved real-client owner questions; documentation gates and the complete local release gate passed with 263 tests and 1,143 assertions.
- a dependency-free local GeoJSON fixture validator for bounded synthetic `Point`, `Feature`, and `FeatureCollection` inputs, with strict UTF-8, WGS84 coordinate, regular-file, 1 MiB, depth-32, and non-disclosing CLI safeguards; nine focused tests, independent review, and the complete local release gate passed with 263 Laravel tests and 1,143 assertions.
- an implemented, technically verified repository-evidence-only OpenStreetMap-facing contract map covering read/write ownership, versioning, image/anonymizer/GeoServer boundaries, attribution/privacy/license unknowns, and owner questions; independent review and the complete local release gate passed with 263 Laravel tests and 1,143 assertions, 58 clean Markdown files, 310 clean public-content candidates, zero dependency advisories, and a 77-commit secret scan, with API/geospatial owner review still required.
- a fail-closed legacy import preflight foundation for restricted schema/aggregate-count evidence; 14 focused synthetic/mocked tests, independent security review, and the complete local release gate passed with 277 Laravel tests and 1,287 assertions, 60 clean Markdown files, 317 clean public-content candidates, zero dependency advisories, and a 78-commit secret scan, while real PostgreSQL/staging evidence is still required.
- a database-free, fail-closed identity import mapping validator for strict schema fingerprints, owner roles, PII classification, stable external IDs, nullability, and password-strategy decisions; 13 focused synthetic tests with 96 assertions and independent security review passed, and the complete local release gate passed with 290 Laravel tests and 1,383 assertions, 62 clean Markdown files, 325 clean public-content candidates, zero dependency advisories, and a 79-commit secret scan. No real mapping, approval, user data, database, or importer is included.
- a database-free deterministic import-schema fingerprint contract and CLI that canonicalizes strict bounded PostgreSQL/SQLite descriptors and emits only a lowercase SHA-256 digest; 20 focused synthetic tests with 210 assertions and independent security review passed, and the complete local release gate passed with 310 Laravel tests and 1,593 assertions, 64 clean Markdown files, 332 clean public-content candidates, zero dependency advisories, and an 80-commit secret scan. Real database metadata extraction, descriptors, fingerprints, approvals, and import remain deferred.
- a disabled-by-default, restricted PostgreSQL-only import schema descriptor extractor with a fixed dedicated source connection, Laravel-native URL normalization, application/default endpoint isolation, read-only catalog inspection, strict v1 metadata limits, and private atomic no-overwrite publication. Thirteen focused extractor/publisher tests with 138 assertions and independent security review passed, and the complete local release gate passed with 323 Laravel tests and 1,731 assertions, 66 clean Markdown files, 341 clean public-content candidates, zero dependency advisories, and an 81-commit secret scan. It does not prove source provenance, approvals, target compatibility, mappings, row safety, or importer readiness; production and live database/network tests remain excluded.
- a disabled-by-default target schema descriptor extractor with a fixed canonical `pgsql` policy, fail-closed normalized legacy-endpoint collision guard, read-only catalog extraction, and the same descriptor v1/private publication contract as the source path. Independent security review approved the implementation; the complete local release gate passed with 336 Laravel tests and 1,829 assertions, 68 clean Markdown files, 350 clean public-content candidates, zero dependency advisories, and an 82-commit secret scan. Live target evidence, fingerprints, mapping approval, and import remain pending.

## Deliberately Disabled Or Incomplete

- AI dispatch and result stages are independently disabled outside approved environments.
- GeoServer delivery and cache invalidation are not active.
- Address enrichment and UKM scoring are disabled pending staging capacity/index/behavior evidence.
- The modern web feature-detail path remains behind client flags pending canonical/legacy comparison.
- Image anonymization holdback and end-to-end staging upload cleanup still require infrastructure proof.
- A replacement operator dashboard has not been built; only approved active workflows will be rebuilt.
- Real source/target metadata extraction, the owner-approved identity mapping, and write-capable legacy-to-modern import/backfill remain incomplete.
- Final browser HttpOnly session/BFF and direct social-provider verification remain open.

## Release Blockers

- representative staging deployment and side-by-side API/client comparison;
- production credential rotation and approved repository-history handling where previously exposed;
- backup/PITR and isolated restore evidence owned by infrastructure;
- anonymizer, image cache, AI, GeoServer, and mobile/mapilio-kit staging evidence;
- dashboards, alerts, owners, canary scope, and rollback exercise;
- private vulnerability reporting verification, branch protection, license selection, and confidential conduct-reporting ownership before public release.

See [release readiness](operations/release-readiness.md) for the complete gate and the external [roadmap](https://github.com/mapilio/backend/issues) for public work once issues are published.
