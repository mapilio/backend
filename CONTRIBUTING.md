# Contributing To Mapilio Backend

Thank you for helping improve Mapilio's open mapping and street-level imagery infrastructure. The project is under active modernization, so contributions should preserve published behavior while making internals cleaner, safer, and easier to operate.

## Before Starting

1. Read the [project status](docs/project-status.md) and [ecosystem architecture](docs/architecture/ecosystem.md).
2. Search existing issues before opening a new one.
3. Use the bug, feature, or documentation issue form. Keep security reports private under [SECURITY.md](SECURITY.md).
4. For large API, schema, identity, privacy, upload, AI, or GeoServer changes, open an issue before implementation so maintainers can confirm scope and compatibility.

The [triage policy](docs/community/issue-triage.md) explains how scope is accepted and when `good first issue` or `help wanted` applies. Drafts in the [initial issue catalog](docs/community/initial-issue-catalog.md) are not open work until maintainers publish them after the public-release gates.

## Local Setup

Follow [local development](docs/operations/local-development.md). The supported contributor path uses disposable SQLite and synthetic fixtures. Do not connect development commands to a production, shared, or write-capable remote database.

## Development Rules

- Follow existing Laravel domain, action, query, job, request, and controller boundaries.
- Preserve legacy response behavior only where an active compatibility contract requires it.
- Put new public behavior under an explicit versioned API namespace.
- Update `docs/api/openapi-v1.json` and tests with every modern API contract change, then run `npm run build:api-docs` and commit the regenerated reference.
- Keep controllers thin and move business rules into domain code.
- Make queue jobs idempotent, timeout-aware, observable, and independently disableable where they call external systems.
- Use parameterized database APIs and prove index changes with representative query plans.
- Keep external writes and production side effects disabled in tests.
- Do not recreate PyroCMS modules, generic dynamic dispatch, or unused dashboard features.
- Avoid unrelated refactors in the same pull request.

## Privacy And Test Data

Use synthetic `example.test`/`example.invalid` identities, generated coordinates, and tiny generated media fixtures. Never commit or post:

- credentials, tokens, keys, cookies, or secret-bearing URLs;
- production database rows, dumps, logs, backups, or infrastructure manifests;
- personal data, private account details, or precise non-public locations;
- real street imagery, especially unblurred faces or license plates;
- internal hosts, storage paths, IP addresses, incident evidence, or provider identifiers that are not already approved public contracts.

## Verification

Run:

```bash
scripts/release/verify-local-readiness.sh
```

At minimum, targeted tests must pass while developing. Before review, the complete gate must pass. GitHub additionally validates the schema on disposable PostgreSQL 14/PostGIS.

`npm run check:api-docs` regenerates the public API reference and fails when the committed output is stale. Edit the OpenAPI source or generator, never the files under `public/docs/api` directly.

`npm run check:license-state` verifies the explicit proprietary/unlicensed package metadata, pending OpenAPI status, and absence of a root project-license file. It guards the current owner-decision state; it does not select terms or classify third-party dependency licenses.

`npm run audit:public-content` checks the candidate tree and complete reachable history without printing matched values. Use `--show-locations` only in a trusted local environment and never paste a private finding into public collaboration surfaces.

Tests should cover success, validation, authorization, disabled behavior, idempotent retry, safe failure envelopes, and relevant compatibility shapes. Mock external services unless a dedicated production-blocked staging harness already exists.

## Pull Requests

- Keep the title concise and describe one coherent change.
- Explain user/API impact, compatibility, security/privacy considerations, schema or queue effects, and rollback/disable behavior.
- Link the issue or ADR that owns the decision.
- Include sanitized test evidence, never production evidence.
- Update documentation and the public status when behavior or an operational gate changes.
- Do not mark external staging or production gates complete without owner-approved evidence.

Maintainers may request smaller changes, additional contract fixtures, an ADR, or explicit owner decisions before accepting work in sensitive ecosystem areas.

## Licensing Gate

The repository owners have not selected a project license. Root package metadata deliberately remains proprietary/unlicensed while this decision is pending. Contributions cannot be treated as open-source licensed until a reviewed `LICENSE`, matching package metadata, and contribution terms are added. See [public-release decisions](docs/governance/public-release-decisions.md).
