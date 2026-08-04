# Mapilio Backend Roadmap

This public roadmap summarizes direction without exposing private infrastructure or release evidence. Detailed operational decisions remain in architecture records and the release-readiness gate.

## Principles

- Preserve active client contracts before changing implementation.
- Put new public behavior under explicit API versions.
- Replace PyroCMS internals with focused Laravel domains, not one-to-one module copies.
- Keep image storage, anonymization, AI inference, and GeoServer as explicit service boundaries.
- Require synthetic tests, fail-closed configuration, staging evidence, and reversible rollout for risky work.
- Publish no secrets, personal data, real imagery, private coordinates, or sensitive operational evidence.

## Now

- use the implemented fail-closed legacy import preflight, deterministic schema fingerprint, and identity mapping validator foundations while database metadata extraction, the real owner-approved mapping, and write-capable import/backfill remain open; these do not complete the roadmap item;
- continue the active legacy API compatibility inventory and contract coverage; the registered-v1-alias negative-contract parity slice is covered for mobile profile/email-modal/OneSignal, imagery uploads, and image reports, but this is an incremental milestone rather than full client/staging compatibility or full inventory completion;
- obtain an owner decision on the existing `/api/v2/leaderboard`, `/api/leaderboard-organization-v2`, and `/api/v2/leaderboard-winner` contracts: whether they should be formally owned/frozen, and whether the two distinct score variants need explicit clean versioned API paths; `/api/v2/leaderboard` and `/api/leaderboard-organization-v2` select image-score behavior through route defaults, while `/api/v2/leaderboard-winner` does not use `score_version`;
- connect restricted PostgreSQL source/target metadata extraction to the deterministic fingerprint contract, then obtain owner decisions for identity keys, duplicate emails, password strategy, roles/status/profile retention, and approve the schema-fingerprinted identity mapping before importer design;
- finish identity/session boundaries and route authorization review;
- harden upload/privacy holdback across image server, TrueNAS cache, and anonymizer;
- define only the operator dashboard workflows that Mapilio still uses;
- expand privacy-bounded metrics, queue telemetry, and database performance evidence;
- prepare representative staging without connecting public contributor workflows to production.
- complete the GitHub administrator/signing readiness tooling and public runbook; keep the second administrator, restricted evidence, non-production drill, Dependabot/bot merge alternative, and signing/enforcement activation pending owner decisions.
- operate under the temporary owner-approved solo-maintainer policy: `main` retains its required pull-request review object, but the live review requirements report zero approvals, no CODEOWNERS review, no stale-approval dismissal, and no last-push approval; this is not independent review or security/governance readiness. Strict up-to-date five-check protection, conversation resolution, linear history, no force-push/deletion, disabled administrator enforcement, and disabled required signatures remain in force.

## Next

- rehearse migrations and backfills on an isolated representative PostgreSQL/PostGIS copy;
- compare old/new API responses and web/mobile/mapilio-kit behavior side by side;
- validate AI dispatch/callback limits, retries, quarantine, and key rotation;
- validate versioned GeoServer layers, access control, cache invalidation, and rollback;
- implement the minimal Laravel operator dashboard approved by workflow owners;
- complete backup/PITR restore evidence, dashboards, alerts, canary scope, and runbooks;
- rotate exposed credentials and complete approved history/public-content review.

## Later

- route-by-route or canary production migration with measured rollback windows;
- retire compatibility routes only after client telemetry and deprecation policy allow it;
- remove the legacy database from runtime after backfill and reconciliation are accepted;
- publish stable releases and host the generated API reference at a versioned public URL;
- grow community-owned tests, SDK examples, localization, data tools, and OpenStreetMap integrations.

## Community Work

Good first public issues should be bounded, synthetic, and independent of private infrastructure. The [initial issue catalog](docs/community/initial-issue-catalog.md) contains publication-ready drafts for documentation, API contracts, tests, localization, data tooling, performance safeguards, and OSM-facing contract documentation. The [triage policy](docs/community/issue-triage.md) defines safety screening, labels, acceptance, response targets, and closure behavior.

C001, the deterministic relative Markdown link check, C002, the synthetic local API cookbook, C003, validated synthetic OpenAPI examples, C004, the focused synthetic legacy mobile-auth fixture helper, C005, the isolated database query-budget assertion, C006, the public API message compatibility and localization-readiness inventory, and C007, the bounded synthetic GeoJSON fixture validator, are complete in the repository. C008, the repository-evidence-only OpenStreetMap-facing contract map, is implemented and technically verified, but API/geospatial owner review remains pending. All issue drafts remain non-publishable until the governance and launch gates below are complete; C008 does not imply OSMF endorsement, authorization, or an active integration.

Do not begin a schema redesign, auth change, upload protocol change, AI/GeoServer integration, or privacy-sensitive workflow from this summary alone. Open a scoped issue and follow [CONTRIBUTING.md](CONTRIBUTING.md).

Release status is governed by [docs/operations/release-readiness.md](docs/operations/release-readiness.md), not by completion percentages in this file.
