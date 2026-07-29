# Community Issue Triage

This policy defines how public backend issues should be screened, classified, accepted, and closed. It becomes operational only after repository owners assign maintainers, select contribution terms, and approve community contribution intake.

## Safety Boundary

Public issues may contain synthetic reproductions, public contract references, and sanitized test output. They must not contain credentials, personal data, production records, real imagery, non-public coordinates, database dumps, logs, request headers, private hosts, infrastructure configuration, or incident evidence.

Suspected vulnerabilities do not enter the public queue. Follow [SECURITY.md](../../SECURITY.md). Remove exposed sensitive content from public view using GitHub's supported moderation tools, preserve required evidence privately, and follow the private incident process without repeating the material in comments.

## Intake Lifecycle

1. **Safety screen:** confirm the report is suitable for a public repository. Stop public discussion when security, privacy, or production evidence appears.
2. **Ownership screen:** confirm the backend owns the behavior. Redirect web, mobile, image-server, AI-model, anonymizer, GeoServer-administration, or infrastructure work to its owning project when possible.
3. **Reproduction screen:** require an affected revision and a minimal synthetic reproduction for bugs. Ask for observable outcomes and affected consumers for proposals.
4. **Classification:** apply one type, one status, the smallest useful set of area labels, and a risk label when specialist review is required.
5. **Decision:** accept with explicit scope and verification, request information, mark an external blocker, record an owner decision, close as duplicate/invalid/wontfix, or move a vulnerability to the private process.

Accepted issues must identify the intended behavior, files or domain boundary when known, acceptance criteria, verification commands, and explicit exclusions. An issue is not implementation approval for schema, authentication, upload, privacy, AI, GeoServer, or production behavior unless the required owner and architecture reviews are recorded.

## Label Set

The repository's existing `bug`, `documentation`, `enhancement`, `good first issue`, `help wanted`, `duplicate`, `invalid`, `question`, and `wontfix` labels remain the public type and contribution labels.

Create the following labels immediately before publishing the initial issue set:

| Label | Color | Meaning |
| --- | --- | --- |
| `status: needs-triage` | `ededed` | Awaiting the initial safety, ownership, and reproduction screen. |
| `status: accepted` | `0e8a16` | Scope and acceptance criteria are approved for implementation. |
| `status: needs-info` | `fbca04` | Reporter or owner information is required before a decision. |
| `status: blocked-external` | `b60205` | Progress requires a client, service, staging, or owner-controlled dependency. |
| `status: owner-decision` | `d4c5f9` | Product, legal, privacy, or operational authority must decide. |
| `area: api` | `1d76db` | Versioned or compatibility API contracts. |
| `area: docs` | `0075ca` | Public documentation and examples. |
| `area: identity` | `5319e7` | Authentication, authorization, users, and sessions. |
| `area: imagery` | `006b75` | Sequence and imagery metadata or upload contracts. |
| `area: ai` | `c2e0c6` | Prediction dispatch, callback, persistence, and status. |
| `area: geospatial` | `2cbe4e` | PostGIS, Geo publication, GeoServer contracts, and OSM-facing data. |
| `area: operations` | `0052cc` | Queues, runtime, backup, observability, and release tooling. |
| `area: tooling` | `bfdadc` | Contributor and test tooling without production access. |
| `risk: architecture-review` | `e99695` | Cross-domain or durable contract decision required. |
| `risk: privacy-review` | `d93f0b` | Imagery, location, identity, retention, or disclosure review required. |

Do not create a public `security` label or use public issues as a vulnerability queue.

## Contribution Labels

Apply `good first issue` only when the work:

- runs entirely with the documented synthetic local setup;
- touches a small, named surface with no production or staging dependency;
- has objective acceptance criteria and verification commands;
- does not change authentication, authorization, schema, upload protocols, privacy behavior, external-service writes, or published response compatibility;
- can be reviewed without private context.

Use `help wanted` for accepted work that is public and bounded but benefits from deeper Laravel, OpenAPI, testing, performance, localization, or geospatial experience.

## Response And Closure Policy

The following are public-launch targets, not support guarantees:

- screen a new issue within seven calendar days;
- respond to a complete `good first issue` pull request within seven calendar days;
- after 30 days without requested information, post one reminder and close 14 days later if no response arrives;
- review `status: blocked-external` issues at least quarterly;
- never auto-close an accepted issue merely because it is old.

Closed issues may be reopened when new synthetic reproduction evidence or an owner decision changes the outcome. Lock a conversation only for conduct, spam, repeated disclosure risk, or sustained unproductive behavior under the Code of Conduct.

## Required Roles Before Contribution Intake

Owners must assign, outside this repository where contact details are private:

- a rotating intake maintainer and backup;
- domain reviewers for API/identity, database/geospatial, imagery/privacy, AI, mobile compatibility, and operations;
- a private security contact and confidential conduct-reporting path;
- authority for final scope disputes and repository visibility reversal.

The initial catalog is in [initial-issue-catalog.md](initial-issue-catalog.md). `main` protection and private vulnerability reporting are enabled. Publish from the catalog only after the license/contribution gate, role assignment, labels, and non-maintainer verification of the private reporting flow are ready.
