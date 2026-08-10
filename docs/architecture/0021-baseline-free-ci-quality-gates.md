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
7. Larastan/PHPStan level 6 over the complete `app/Domain/GeoPublishing` directory
8. Larastan/PHPStan level 6 over the complete `app/Domain/IdentityAccess` directory
9. Larastan/PHPStan level 6 over the complete `app/Domain/ImageryReports` directory
10. Larastan/PHPStan level 6 over the complete `app/Domain/Organizations` directory
11. Larastan/PHPStan level 6 over the complete `app/Domain/Projects` directory
12. Larastan/PHPStan level 6 over the complete `app/Domain/BillingCatalog` directory
13. Larastan/PHPStan level 6 over the complete `app/Domain/PublicContent` directory
14. Larastan/PHPStan level 6 over the complete `app/Domain/Gamification` directory
15. Larastan/PHPStan level 6 over the complete `app/Domain/ImageryUploads` directory
16. Larastan/PHPStan level 6 over the complete `app/Domain/OperationsDashboard` directory
17. Larastan/PHPStan level 6 over the complete `app/Domain/DataMigration` directory
18. Laravel configuration cache creation and clearing
19. the complete Laravel test suite

The global analysis level remains 5. `app/Jobs`, `app/Domain/AiJobsPredictions`, `app/Domain/GeoPublishing`, `app/Domain/IdentityAccess`, `app/Domain/ImageryReports`, `app/Domain/Organizations`, `app/Domain/Projects`, `app/Domain/BillingCatalog`, `app/Domain/PublicContent`, `app/Domain/Gamification`, `app/Domain/ImageryUploads`, `app/Domain/OperationsDashboard`, and `app/Domain/DataMigration` are directory-wide level 6 islands, so current and future classes under those directories are automatically included. The GeoPublishing island closes exactly two level 6 iterable-contract findings: the grouped road parameter in `CreateRoadLineForSequence::insertRoad()` and the row-shape return from `UploadedRoadsByGroupQuery::get()`. The IdentityAccess island closes exactly 10 level 6 iterable-contract findings: one `CheckMobileEmailModal::check()` return, three `LegacyMobileAuth` input parameters, three `LegacyMobileAuthException` message/error contracts, one `MobileProfileQuery::get()` return, and two `PublicUserProfileQuery` returns. The ImageryReports island closes exactly one level 6 iterable-contract finding: the `CreateImageReport::create()` return shape. The Organizations island closes exactly two level 6 iterable-contract findings: the row-list return from `OrganizationLeaderboardQuery::get()` and the mapped-row return from `OrganizationLeaderboardQuery::mapRow()`. The Projects island closes exactly three level 6 iterable-contract findings: the `CreateMobileProjectJob::create()` return shape, the row-list return from `MobileUserJobsQuery::get()`, and the mapped-row return from `MobileUserJobsQuery::row()`. The BillingCatalog island closes exactly six level 6 iterable-contract findings in `BillingPlanQuery`: the `packages()`, `hosting()`, `paginated()`, `mapPackage()`, `mapHosting()`, and `pagination()` return shapes. The PublicContent island closes exactly nine level 6 iterable-contract findings in `BlogContentQuery` and `CatalogQuery`: the three public envelope returns, the three row mappers, the two pagination contracts, and the catalog response return. The Gamification island closes exactly 17 findings in `GamificationBadgesQuery`: 13 missing iterable value contracts, one missing return type, and three missing parameter types. The ImageryUploads island had zero pre-existing level 6 findings and adds directory-wide level-6 static-analysis regression coverage for code supporting mobile/Mapilio Kit upload metadata, transactional persistence, geometry generation, quality scoring, and enrichment job dispatch. The OperationsDashboard island had zero pre-existing level 6 findings and adds directory-wide level-6 static-analysis regression coverage for code supporting fail-closed backup-readiness evidence validation. The DataMigration island closes exactly 21 missing iterable-value findings across six DataMigration classes: `ComputeImportSchemaFingerprint`, `ExtractImportSchemaDescriptor`, `PostgresqlCatalogReader`, `PrivateJsonPublisher`, `RunLegacyImportPreflight`, and `ValidateImportMapping`. These gates do not claim that the whole application passes level 6 or that runtime behavior changed.

The API-contract job uses a locked npm tree and Redocly `recommended-strict` to run npm advisory audit, OpenAPI lint, and the Vite production asset build. PHPStan and OpenAPI tool versions are committed to Composer/npm lockfiles. GitHub setup actions are pinned to full commit SHAs.

No PHPStan baseline, ignored identifier, Redocly ignore file, or warning allowance is committed. Findings must be fixed at their type or contract boundary.

## Consequences

Database connection resolution now returns Laravel's concrete connection type after validating the configured connection name. AI and Geo query results fail safely when their database representation is unexpected. Portable marketplace mapping uses explicit arrays instead of dynamic object properties, and smaller header/timestamp type mismatches are corrected.

The first gate is intentionally level 5 rather than a claim of maximum static coverage. Protected `main` now requires both Quality jobs, the disposable PostgreSQL/PostGIS migration job, and the separate full-history Gitleaks job, all bound to the GitHub Actions application. Raising the analysis level, analyzing migrations/tests, adding mutation or architecture tests, mandatory pull-request review, and administrator enforcement remain future improvements. The separate full-history secret scan remains required because dependency and static-analysis jobs do not replace credential detection.
