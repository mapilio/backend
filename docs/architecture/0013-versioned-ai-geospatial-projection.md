# ADR 0013: Versioned AI Geospatial Projection

## Status

Accepted for local and staging integration. Database preparation is disabled by default. GeoServer catalog changes and external delivery remain blocked.

## Context

The current public map contract cannot be replaced in place. Read-only inspection on 2026-07-15 found these active dependencies:

- web object tiles use source layer `map_features` and fetch object detail from WFS layer `mapilio:features`
- mobile point tiles use `mapilio:map_points`
- web and mobile road tiles use `mapilio:map_roads_line`
- mobile point detail uses WFS layer `mapilio:captured_roads_point`

Public WFS samples reported approximately 1.66 million rows for `features`, 18.64 million rows for `map_points`, and 24.95 million rows for `map_roads_line`. The legacy `features` schema exposes denormalized `matched_points`, image hash, filename, and resolution fields consumed by the current web client.

The `captured_roads_point` request currently fails because its configured `captured_roads_point_v2` database schema does not exist. A sample `map_points` WMTS tile timed out while a sample `map_roads_line` tile returned. GeoServer REST catalog endpoints require authentication, and no catalog mutation was attempted.

Production PostgreSQL reports version 14.23. PostGIS remains a required deployment prerequisite.

## Decision

The modern backend does not overwrite or silently repoint the existing `features` layer. Existing clients remain on the legacy layer until a coordinated client migration is tested.

Migration `2026_07_15_020000_create_versioned_ai_geo_projection` adds:

- a generated PostgreSQL `geometry(Point, 4326)` column on `ai_detection_features`
- a GiST spatial index on that generated geometry
- a clean `mapilio_ai_features_v1` database view for a future `mapilio:ai_features_v1` GeoServer layer
- immutable reconciliation history in `geospatial_publication_checks`
- preparation and reconciliation timestamps on the publication outbox

The geometry column is `GENERATED ALWAYS` from canonical longitude and latitude. Application code cannot write a conflicting geometry.

`PrepareAiDetectionPublication` is a unique, retryable, disabled-by-default job. It validates:

- the source receipt is processed and successful
- receipt, outbox, and canonical feature counts match
- every canonical feature belongs to the publication sequence
- all coordinates are valid
- every canonical feature id appears in the versioned view with geometry
- configured database-view and GeoServer-layer identifiers are allowlisted identifiers

A passed check changes the outbox from `blocked` to `ready`. It does not mark the result `published`. Failed checks store a safe error and remain retryable.

## Feature flags

- `MAPILIO_GEO_PUBLICATION_REGISTRATION_ENABLED=false`
- `MAPILIO_GEO_PUBLICATION_PREPARATION_ENABLED=false`
- `MAPILIO_GEO_PUBLICATION_DELIVERY_ENABLED=false`

The delivery flag is reserved and is intentionally unused until authenticated catalog inspection and staging activation are complete.

## Staging activation gates

1. Install or verify PostGIS before running the migration.
2. Run the migration against the modern staging PostgreSQL database and inspect the generated-column and GiST-index definitions.
3. Compare canonical table and view counts, bounds, SRID, null geometry count, and representative ids.
4. Inspect the authenticated GeoServer workspace, store, layer, style, security, and GeoWebCache configuration.
5. Register `mapilio:ai_features_v1` against the modern store without changing legacy `features`.
6. Test WFS schema, vector tiles, filters, cache behavior, and rollback with representative data.
7. Validate the versioned backend detail API from ADR 0014 in staging, then migrate web object-detail reads away from legacy `matched_points` JSON behind a rollback flag.
8. Only after verification, implement the external delivery worker and allow `ready` to become `published`.

## Consequences

Canonical AI points now have a deterministic PostGIS publication shape without copying the PyroCMS detection graph. Existing web and mobile contracts remain untouched. Publication readiness is measurable, but external publication cannot be claimed prematurely.
