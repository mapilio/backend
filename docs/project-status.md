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

## Deliberately Disabled Or Incomplete

- AI dispatch and result stages are independently disabled outside approved environments.
- GeoServer delivery and cache invalidation are not active.
- Address enrichment and UKM scoring are disabled pending staging capacity/index/behavior evidence.
- The modern web feature-detail path remains behind client flags pending canonical/legacy comparison.
- Image anonymization holdback and end-to-end staging upload cleanup still require infrastructure proof.
- A replacement operator dashboard has not been built; only approved active workflows will be rebuilt.
- Legacy-to-modern backfill/import commands and source-to-target mappings remain to be implemented.
- Final browser HttpOnly session/BFF and direct social-provider verification remain open.

## Release Blockers

- representative staging deployment and side-by-side API/client comparison;
- production credential rotation and approved repository-history handling where previously exposed;
- backup/PITR and isolated restore evidence owned by infrastructure;
- anonymizer, image cache, AI, GeoServer, and mobile/mapilio-kit staging evidence;
- dashboards, alerts, owners, canary scope, and rollback exercise;
- private vulnerability reporting verification, branch protection, license selection, and confidential conduct-reporting ownership before public release.

See [release readiness](operations/release-readiness.md) for the complete gate and the external [roadmap](https://github.com/mapilio/backend/issues) for public work once issues are published.
