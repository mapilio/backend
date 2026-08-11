# ADR 0021: Baseline-free CI quality gates

## Status

Accepted and enforced by GitHub Actions for the modern backend.

## Context

The repository previously ran a full-history secret scan but had no general pull-request gate for PHP formatting, static analysis, dependency advisories, tests, or OpenAPI validity. The first Larastan level 5 run found 56 issues, primarily from database connection interfaces and unguarded query result types. Pint found two existing formatting failures, and strict OpenAPI lint found four metadata warnings.

An initial global Larastan level 6 probe found 137 findings. It was deliberately not bulk-suppressed: no baseline, ignore, or broad suppression was introduced.

A generated static-analysis baseline or broad ignore rules would make CI green while preserving unclear type boundaries. Floating GitHub Action tags and unpinned command-line downloads would also weaken reproducibility.

## Decision

The `Quality` workflow runs once for pull requests targeting `main`, again for the merged `main` revision, and on manual dispatch, with read-only repository permission and no persisted checkout credential. Feature-branch pushes are intentionally covered by the pull-request run instead of creating duplicate jobs.

The PHP 8.2 job runs:

1. strict Composer metadata validation
2. locked dependency installation and advisory audit
3. Laravel Pint in check mode
4. baseline-free Larastan/PHPStan level 6 over `app`, `bootstrap/app.php`, `database`, `routes`, and `tests`
5. Laravel configuration cache creation and clearing
6. the complete Laravel test suite

The earlier migration used directory-wide level 6 islands to fix findings in bounded batches without suppressions. After those islands were complete, the final application-wide probe had two findings: the legacy user-points pagination contract and the sprite metadata map contract. Both now have explicit return shapes. The island-specific Composer scripts and repeated CI/release steps have been retired in favor of one permanent level 6 gate, so every current and future class in the configured application scope receives the same coverage.

The complete `database` tree was then measured separately at level 6 and had zero findings. Migrations, seeders, and factories are therefore part of the same permanent gate rather than a second command. The first test-suite probe found 38 findings: 15 unsafe object-property reads, 10 iterable values without item types, four missing return types, three undefined mock methods, three generic types without parameters, and one finding each for an unused union member, an always-true comparison, and an already-narrowed assertion. Those contracts were fixed without changing production behavior, adding suppressions, or introducing a baseline. The complete `tests` tree is now part of the same permanent level 6 gate.

A configured production-scope level 7 probe found 419 findings: 387 dynamic database-row property findings and 32 other type findings. Level 7 is not enabled and none of those findings is suppressed; query-row boundaries must be modeled deliberately before promotion.

The API-contract job uses a locked npm tree and Redocly `recommended-strict` to run npm advisory audit, OpenAPI lint, and the Vite production asset build. PHPStan and OpenAPI tool versions are committed to Composer/npm lockfiles. GitHub setup actions are pinned to full commit SHAs.

No PHPStan baseline, ignored identifier, Redocly ignore file, or warning allowance is committed. Findings must be fixed at their type or contract boundary.

## Consequences

Database connection resolution now returns Laravel's concrete connection type after validating the configured connection name. AI and Geo query results fail safely when their database representation is unexpected. Portable marketplace mapping uses explicit arrays instead of dynamic object properties, and smaller header/timestamp type mismatches are corrected.

Protected `main` now requires both Quality jobs, the disposable PostgreSQL/PostGIS migration job, and the separate full-history Gitleaks job, all bound to the GitHub Actions application. Level 7 promotion, mutation or architecture tests, mandatory pull-request review, and administrator enforcement remain future improvements. The separate full-history secret scan remains required because dependency and static-analysis jobs do not replace credential detection.
