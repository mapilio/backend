# ADR 0021: Baseline-free CI quality gates

## Status

Accepted and enforced by GitHub Actions for the modern backend.

## Context

The repository previously ran a full-history secret scan but had no general pull-request gate for PHP formatting, static analysis, dependency advisories, tests, or OpenAPI validity. The first Larastan level 5 run found 56 issues, primarily from database connection interfaces and unguarded query result types. Pint found two existing formatting failures, and strict OpenAPI lint found four metadata warnings.

A generated static-analysis baseline or broad ignore rules would make CI green while preserving unclear type boundaries. Floating GitHub Action tags and unpinned command-line downloads would also weaken reproducibility.

## Decision

The `Quality` workflow runs on pushes, pull requests, and manual dispatch with read-only repository permission and no persisted checkout credential.

The PHP 8.2 job runs:

1. strict Composer metadata validation
2. locked dependency installation and advisory audit
3. Laravel Pint in check mode
4. Larastan/PHPStan level 5 over `app`, `bootstrap/app.php`, and `routes`
5. Laravel configuration cache creation and clearing
6. the complete Laravel test suite

The API-contract job uses a locked npm tree and Redocly `recommended-strict` to run npm advisory audit, OpenAPI lint, and the Vite production asset build. PHPStan and OpenAPI tool versions are committed to Composer/npm lockfiles. GitHub setup actions are pinned to full commit SHAs.

No PHPStan baseline, ignored identifier, Redocly ignore file, or warning allowance is committed. Findings must be fixed at their type or contract boundary.

## Consequences

Database connection resolution now returns Laravel's concrete connection type after validating the configured connection name. AI and Geo query results fail safely when their database representation is unexpected. Portable marketplace mapping uses explicit arrays instead of dynamic object properties, and smaller header/timestamp type mismatches are corrected.

The first gate is intentionally level 5 rather than a claim of maximum static coverage. Raising the level, analyzing migrations/tests, adding mutation or architecture tests, and requiring both Quality jobs in branch protection remain future improvements. The separate full-history secret scan remains required because dependency and static-analysis jobs do not replace credential detection.
