# ADR 0003: Legacy Compatibility Endpoints

Date: 2026-07-08

## Decision

Preserve active legacy API paths at the HTTP edge, but implement them with explicit Laravel controllers and domain query services instead of rebuilding the old dynamic resource layer.

Every preserved legacy endpoint should also receive a documented `/api/v1` alias. Future breaking changes must use a new versioned path instead of changing the existing response contract in place.

## Context

The backend is consumed by the web platform, mobile apps, AI workflows, and community integrations. Some public endpoints were not originally versioned, but those paths are already production contracts.

The modern backend now preserves:

- `/api/country-image-count`
- `/api/leaderboard`
- `/api/get-point-by-user`
- `/api/sequence-detail`
- `/api/embed/{sequenceUuid}`
- `/api/get-uploaded-roads-group`

It also exposes v1 aliases:

- `/api/v1/imagery/country-image-count`
- `/api/v1/imagery/leaderboard`
- `/api/v1/imagery/user-points`
- `/api/v1/imagery/sequence-detail`
- `/api/v1/imagery/embed/{sequenceUuid}`
- `/api/v1/geo/uploaded-roads-group`

## Consequences

- Compatibility controllers are allowed to mirror old envelopes and pagination quirks when clients depend on them.
- Business logic belongs in domain classes so it can be optimized, tested, and later moved to clean schema tables or rollups.
- Dynamic method dispatch is not carried forward as a framework pattern.
- Internal filters and deployment-specific behavior must be configurable, not hard-coded into public source.
- Contract tests are required before an endpoint is considered ready for cutover.

## Verification

The leaderboard compatibility pass added feature coverage for:

- legacy leaderboard response shape
- versioned leaderboard alias equivalence
- point-by-user wrapper and pagination envelope
- versioned point-by-user alias data equivalence
- missing `user_id` error shape
- sequence detail response ordering, empty-result shape, and missing `sequence_uuid` error shape
- embed image metadata wrapper, entry ordering, and unknown sequence 404 shape
- uploaded road group GeoJSON serialization, empty-result shape, and missing `group_key` error shape

Live read-only smoke checks compared the old and new JSON for sampled leaderboard and point-by-user requests with exact diffs.
