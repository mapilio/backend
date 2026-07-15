# ADR 0012: AI Completion and Geo Publication Outbox

## Status

Accepted for local and staging integration. Both flows are disabled by default. ADR 0013 adds database preparation and reconciliation; GeoServer delivery is not implemented or enabled.

## Context

Canonical AI result persistence does not by itself complete the production workflow. Existing clients also observe status on the legacy processing and sequence-detail records, while map layers eventually need a controlled geospatial publication path.

The old prediction worker writes status directly after expanding the result into legacy tables. No direct GeoServer REST layer-management call was found in the scanned backend, which indicates that GeoServer likely consumes PostgreSQL tables or views configured outside the application. A modern implementation must therefore avoid claiming that a result is published before the live catalog, store, layer, SQL-view, cache, and security contracts are known.

## Decision

The modern result worker may dispatch two independent, unique, retryable follow-up jobs after canonical persistence. Dispatch is safe after both a new write and an idempotent retry.

### Processing-status projection

`ProjectPredictionProcessingStatus` records one durable `ai_prediction_status_projections` row per callback receipt before changing legacy state. It requires:

- a processed callback receipt
- exactly one active processing row for the response id
- exactly one active sequence-detail row for the processing sequence

For `SUCCESS`, it sets the processing request to `SUCCESS` and the sequence to `completed` with legacy processing status `3`. For `ERROR`, it sets the request to `ERROR` and returns the sequence to `uploaded` with status `1` and a generic message that cannot leak an AI response body.

Legacy updates run in one legacy-database transaction. The modern audit record is then marked `projected`. A crash between those databases can repeat the same deterministic assignments safely. Completed projections are idempotent.

This flow is controlled by `MAPILIO_AI_STATUS_PROJECTION_ENABLED=false` and has a dedicated queue.

### Geo publication registration

`RegisterAiDetectionPublication` creates one `geospatial_publications` record for a processed successful callback. It reconciles canonical feature count with the signed callback receipt and fails closed on a mismatch or ambiguous sequence ownership.

New entries are intentionally stored as `blocked`, with no external call and no legacy detection-table projection. The record is a durable publication request and reconciliation boundary, not evidence that GeoServer serves the data.

Registration is controlled by `MAPILIO_GEO_PUBLICATION_REGISTRATION_ENABLED=false`. ADR 0013 defines a separate `MAPILIO_GEO_PUBLICATION_PREPARATION_ENABLED=false` gate for PostGIS-view reconciliation. `MAPILIO_GEO_PUBLICATION_DELIVERY_ENABLED=false` remains reserved as a production gate and is not consumed by a delivery worker yet.

## Activation gates

Before GeoServer delivery exists or either flag is enabled outside controlled staging:

1. Inspect the authenticated GeoServer workspace, data store, layers, SQL views, style rules, cache behavior, and write permissions.
2. Identify contracts for at least `map_points`, `map_roads_line`, `captured_roads_point`, and `features` used by current clients.
3. Add canonical PostgreSQL/PostGIS geometry columns and spatial indexes, then verify SRID, count, bounds, and representative feature ids.
4. Choose and test either a canonical table/view publication or an explicitly required legacy compatibility projection.
5. Add delivery retries, reconciliation, operator retry/quarantine controls, metrics, and cache invalidation.
6. Run staging mobile, web, AI, WFS, WMTS, and rollback tests without production writes.

## Consequences

AI completion is now observable and retry-safe without coupling canonical persistence to legacy writes. Geo publication intent is durable, but no result can accidentally be described as published while the external layer contract remains unknown.
