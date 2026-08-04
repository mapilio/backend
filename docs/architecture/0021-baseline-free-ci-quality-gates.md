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
4. Larastan/PHPStan level 5 over `app`, `bootstrap/app.php`, and `routes`
5. Larastan/PHPStan level 6 over the complete `app/Jobs` directory
6. Larastan/PHPStan level 6 over the complete `app/Domain/AiJobsPredictions` directory
7. Laravel configuration cache creation and clearing
8. the complete Laravel test suite

The global analysis level remains 5. `app/Jobs` and `app/Domain/AiJobsPredictions` are directory-wide level 6 islands, so current and future classes under those directories are automatically included. This milestone closes exactly two level 6 iterable-contract findings in `app/Domain/AiJobsPredictions`: the `json(array $value)` helpers in `NormalizePredictionResult` and `PersistPredictionResult`. It does not claim that the whole application passes level 6.

The API-contract job uses a locked npm tree and Redocly `recommended-strict` to run npm advisory audit, OpenAPI lint, and the Vite production asset build. PHPStan and OpenAPI tool versions are committed to Composer/npm lockfiles. GitHub setup actions are pinned to full commit SHAs.

No PHPStan baseline, ignored identifier, Redocly ignore file, or warning allowance is committed. Findings must be fixed at their type or contract boundary.

## Consequences

Database connection resolution now returns Laravel's concrete connection type after validating the configured connection name. AI and Geo query results fail safely when their database representation is unexpected. Portable marketplace mapping uses explicit arrays instead of dynamic object properties, and smaller header/timestamp type mismatches are corrected.

The first gate is intentionally level 5 rather than a claim of maximum static coverage. Protected `main` now requires both Quality jobs, the disposable PostgreSQL/PostGIS migration job, and the separate full-history Gitleaks job, all bound to the GitHub Actions application. Raising the analysis level, analyzing migrations/tests, adding mutation or architecture tests, mandatory pull-request review, and administrator enforcement remain future improvements. The separate full-history secret scan remains required because dependency and static-analysis jobs do not replace credential detection.
