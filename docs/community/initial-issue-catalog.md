# Initial Community Issue Catalog

## Status

Draft. These issue bodies are sanitized and scoped for future publication, but they must not be opened for community implementation until owners select a license and contribution terms, assign triage/domain reviewers, enable repository controls, and approve public launch.

When publishing, recheck every referenced path against the selected release revision, create the labels from [the triage policy](issue-triage.md), assign a domain reviewer, and publish only the issues that still describe unfinished work.

## C001: Add A Deterministic Relative Markdown Link Check (Completed; Non-publishable)

**Catalog status:** Completed in the repository. Keep this draft closed and non-publishable until the governance and launch requirements in the catalog status above are complete.

**Suggested labels:** `documentation`, `good first issue`, `area: docs`, `area: tooling`, `status: completed`

**Outcome:** Broken repository-relative links in tracked Markdown fail locally and in the Quality workflow. Implemented with a dependency-free Node checker and focused unit tests.

**Scope:** Add a small dependency-free checker for relative file links in tracked Markdown. Ignore `http`, `https`, `mailto`, fragment-only links, code fences, generated API assets, dependencies, and ignored files. Resolve links from the containing document and report only repository-relative paths. Add the command to the local release gate and the existing OpenAPI/npm CI job.

**Acceptance criteria:** A valid-link fixture passes; missing, case-mismatched, and repository-escaping paths fail with file and line; paths containing spaces are supported; existing tracked Markdown passes; README documents the focused command.

**Out of scope:** External network link checking, rewriting documentation, production access, and HTML anchor validation.

**Verification completed:** 24 focused checker tests, all 54 tracked Markdown files, and the complete local release gate with 257 Laravel tests and 1,116 assertions passed. No external URL, database, or live service was accessed.

## C002: Add A Synthetic Local API Cookbook (Completed; Non-publishable)

**Catalog status:** Completed in the repository. Keep this draft closed and non-publishable until the governance and launch requirements in the catalog status above are complete.

**Suggested labels:** `documentation`, `good first issue`, `area: api`, `area: docs`, `status: completed`

**Outcome:** A contributor can exercise safe read-only endpoints after the documented SQLite seed without private knowledge.

**Scope:** Add a short cookbook for `/api/v1/system/health` and the seeded `/api/v1/geo/ai-features/900000001` response. Use `127.0.0.1`, synthetic identifiers, `jq` only as an optional formatter, and explain the expected status and top-level response shape.

**Acceptance criteria:** Every command works after the documented local setup; examples contain no account, token, write request, production hostname, real coordinate, or imagery reference; links from README and the documentation index are added.

**Out of scope:** Login examples, upload calls, newsletter writes, production calls, SDK generation, and external-service setup.

**Verification completed:** An isolated local SQLite smoke used shell environment overrides and loopback port `18000`; both `GET` requests returned HTTP 200 and their response assertions passed, followed by cleanup. The complete local release gate also passed with 257 Laravel tests and 1,116 assertions. No external URL, remote database, or live service was accessed.

## C003: Add Validated Synthetic OpenAPI Examples (Completed; Non-publishable)

**Catalog status:** Completed in the repository. Keep this draft closed and non-publishable until the governance and launch requirements in the catalog status above are complete.

**Suggested labels:** `documentation`, `help wanted`, `area: api`, `area: docs`, `status: completed`

**Outcome:** Implemented modern operations show meaningful request and response examples that conform to their schemas.

**Scope:** Add explicit synthetic examples for successful and documented error responses in `docs/api/openapi-v1.json`. Add a test or script that validates examples against their resolved OpenAPI 3.1 schemas using locked tooling. Regenerate the static API reference.

**Acceptance criteria:** Examples use reserved domains and synthetic values; no bearer token resembles a real credential; every added example validates; strict Redocly lint and `npm run check:api-docs` pass; corresponding feature-test shapes remain consistent.

**Out of scope:** New endpoints, contract changes, production payload capture, interactive authorization, and calling any live service.

**Verification completed:** focused validator tests, repository coverage validation, `npm run lint:openapi`, `npm run build:api-docs`, and the complete local release gate with 257 Laravel tests and 1,116 assertions. No live service was used for this bounded documentation/tooling item.

## C004: Extract A Small Synthetic Legacy Test Fixture Builder (Completed; Non-publishable)

**Catalog status:** Completed in the repository. Keep this draft closed and non-publishable until the governance and launch requirements in the catalog status above are complete.

**Suggested labels:** `enhancement`, `good first issue`, `area: tooling`, `status: completed`

**Outcome:** Repeated synthetic user setup in a small pair of mobile compatibility tests becomes easier to read without changing behavior.

**Scope:** Start only with `MobileEmailModalCompatibilityTest` and `MobileProjectJobsCompatibilityTest`. Extract the repeated synthetic account/token setup into a test-only helper that preserves exact row values, response assertions, and transaction isolation.

**Acceptance criteria:** No application code or migration changes; no test assertion is removed; both focused suites pass before and after; helper names describe domain intent; fixture data remains entirely synthetic.

**Out of scope:** A universal factory framework, production data imports, auth redesign, response cleanup, and unrelated test refactors.

**Verification completed:** The two focused files passed before and after extraction with the same 13 tests and 55 assertions; focused PHPStan and formatting checks also passed. The complete local release gate passed with 257 Laravel tests and 1,116 assertions. No remote database, external service, or production system was accessed.

## C005: Add A Reusable Database Query-Budget Assertion (Completed; Non-publishable)

**Catalog status:** Completed in the repository. Keep this draft closed and non-publishable until the governance and launch requirements in the catalog status above are complete.

**Suggested labels:** `enhancement`, `help wanted`, `area: tooling`, `area: api`, `status: completed`

**Outcome:** Bounded-query API tests can express their query budget without duplicating listener/filter bookkeeping.

**Scope:** Extract the query capture used by `AiFeatureDetailApiTest` into a test-only utility with an explicit connection/table filter and maximum or exact budget. Migrate only that test first and document why its canonical graph read remains four bounded queries.

**Acceptance criteria:** The helper unregisters or isolates listeners between tests; an intentional fifth matching query makes a focused regression test fail; unrelated framework queries are excluded explicitly; the existing feature-detail assertions and four-query budget remain unchanged.

**Out of scope:** Production query logging, benchmarking production data, changing SQL, adding indexes, or migrating every test.

**Verification completed:** Six focused helper regressions and the unchanged seven-test feature-detail suite passed together with 13 tests and 72 assertions; focused static analysis, formatting, and independent review also passed. The complete local release gate passed with 263 Laravel tests and 1,143 assertions. No remote database, external service, or production system was accessed.

## C006: Inventory Public API Messages For Localization Readiness (Completed; Non-publishable)

**Catalog status:** Completed in the repository. Keep this draft closed and non-publishable until the governance and launch requirements in the catalog status above are complete.

**Suggested labels:** `documentation`, `help wanted`, `area: api`, `area: docs`, `status: completed`

**Outcome:** Maintainers know which response messages are compatibility-sensitive, internal-only, or candidates for future message codes/localization.

**Scope:** Produce a documentation table from explicit controllers, requests, exceptions, and tests. Record route, status, current message, whether clients assert it, and a recommendation: preserve, add stable code in a future version, or keep internal. Treat the output as an inventory, not a contract change.

**Acceptance criteria:** Dynamic legacy dispatch remains excluded; every statement links to repository evidence; no string is translated or changed; privacy/security error messages are not made more detailed; owner questions are listed separately.

**Out of scope:** Translation files, locale negotiation, changing response envelopes, mobile/web UI text, and production traffic analysis.

**Verification completed:** Explicit routes, controllers, middleware, domain exceptions, and compatibility tests were reviewed; every inventory row links to repository evidence and an independent contract review approved the result. Documentation gates passed across 56 tracked Markdown files, and the complete local release gate passed with 263 Laravel tests and 1,143 assertions. No remote database, external service, or production system was accessed.

## C007: Add A Synthetic GeoJSON Fixture Validator (Completed; Non-publishable)

**Catalog status:** Completed in the repository. Keep this draft closed and non-publishable until the governance and launch requirements in the catalog status above are complete.

**Suggested labels:** `enhancement`, `help wanted`, `area: geospatial`, `area: tooling`, `status: completed`

**Outcome:** Contributors can validate small synthetic GeoJSON fixtures before using them in contract tests.

**Scope:** Add a local-only command or script that reads one bounded file, accepts the GeoJSON types already used by modern API tests, verifies finite WGS84 longitude/latitude ordering and ranges, rejects extra-large or deeply nested input, and emits a concise result without echoing the fixture.

**Acceptance criteria:** Synthetic valid Point and Feature fixtures pass; swapped/out-of-range/non-finite coordinates, unsupported types, excessive size/depth, and malformed JSON fail; the tool performs no database, network, or external-service operation; focused tests cover limits.

**Out of scope:** Production data repair, shapefile support, reprojection, GeoServer publication, uploads, and accepting arbitrary geometry types.

**Verification completed:** Nine focused synthetic tests cover accepted shapes, malformed and unsupported input, coordinate and property failures, strict UTF-8, safe CLI output, regular-file enforcement, and exact 1 MiB/depth-32 boundaries. Documentation links, shell syntax, CI YAML, staged diff checks, and independent security/contract review passed. The complete local release gate passed with 263 Laravel tests and 1,143 assertions, zero dependency advisories, 57 clean Markdown files, 309 public-content candidates, and a 76-commit secret scan. No database, external Mapilio service, staging, or production system was accessed.

## C008: Map The OpenStreetMap-Facing Backend Contract

**Suggested labels:** `documentation`, `help wanted`, `area: geospatial`, `area: docs`, `status: accepted`

**Outcome:** Contributors can distinguish approved public imagery/geospatial contracts from private infrastructure and unverified integration assumptions.

**Scope:** Build a repository-evidence-only contract map covering backend-owned metadata/URLs, versioned geospatial responses, external ownership boundaries, attribution/privacy considerations, and explicit unknowns requiring Mapilio or OSM workflow owners. Use synthetic request/response shapes and link existing architecture/ADR material.

**Acceptance criteria:** The document does not imply OpenStreetMap Foundation endorsement; no production probing, credentials, internal hosts, personal data, real imagery, or non-public coordinates are used; read/write ownership and versioning expectations are explicit; unknown behavior is not presented as fact.

**Out of scope:** Changing OSM integrations, scraping public services, publishing layers, editing imagery, licensing decisions, and making API compatibility promises without owner approval.

**Verification:** maintainer review by the API/geospatial owner, documentation links, and the repository release gate.
