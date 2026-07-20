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

- complete the active legacy API compatibility inventory and contract coverage;
- design repeatable legacy-to-modern import/backfill with source-to-target mappings;
- finish identity/session boundaries and route authorization review;
- harden upload/privacy holdback across image server, TrueNAS cache, and anonymizer;
- define only the operator dashboard workflows that Mapilio still uses;
- expand privacy-bounded metrics, queue telemetry, and database performance evidence;
- prepare representative staging without connecting public contributor workflows to production.

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

Good first public issues should be bounded, synthetic, and independent of private infrastructure. Candidate areas include documentation link checks, API contract fixtures, test readability, localization, safe developer tooling, OpenAPI examples, and non-sensitive performance benchmarks.

Do not begin a schema redesign, auth change, upload protocol change, AI/GeoServer integration, or privacy-sensitive workflow from this summary alone. Open a scoped issue and follow [CONTRIBUTING.md](CONTRIBUTING.md).

Release status is governed by [docs/operations/release-readiness.md](docs/operations/release-readiness.md), not by completion percentages in this file.
