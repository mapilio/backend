# Scheduled Jobs And Geospatial Summary

Date: 2026-07-08

This document records public-safe findings from the legacy scheduler and geospatial code review.

## Scheduled Jobs

The legacy backend currently registers 8 scheduled jobs through Laravel/PyroCMS package scheduling:

| Area | Migration Direction |
| --- | --- |
| Country image count refresh | Rebuild as an explicit rollup refresh job. |
| Score/leaderboard calculation | Rebuild as a tested rollup pipeline, not ad hoc correlated SQL. |
| Road repair/path generation | Confirm active workflow, then rebuild as a queued geospatial job or retire. |
| Prediction processing | Confirm AI workflow ownership, idempotency, retry behavior, and callback contract. |
| Notifications | Rebuild only if the notification workflow is still active. |
| User cleanup | Replace with Laravel-native retention/account cleanup policies. |
| Gamification sync | Retire unless product owner confirms it is still part of Mapilio. |
| Telescope pruning | Replace with modern observability retention policy. |

There are also legacy Mapilio command classes that are available but not currently scheduled. These should not be ported automatically.

## Geospatial Dependencies

The legacy backend contains PostGIS-heavy code paths around imagery, features, locations, roads, shapes, country image counts, and derived geospatial data.

The scanned backend code did not show direct GeoServer layer-management logic. That means GeoServer may be managed externally, through database publication, manual configuration, server-side scripts, or another service.

## Live public layer check: 2026-07-15

Read-only public WFS/WMTS inspection confirmed that current clients depend on `mapilio:features`, `mapilio:map_points`, `mapilio:map_roads_line`, and `mapilio:captured_roads_point`. Approximate WFS totals were 1.66 million features, 18.64 million imagery points, and 24.95 million road features.

The `captured_roads_point` WFS query currently reports that database schema `captured_roads_point_v2` does not exist. A sample `map_points` WMTS request timed out while a sample road request returned. GeoServer REST catalog reads require authentication. No catalog or database write was attempted.

ADR 0013 therefore introduces a separate versioned `mapilio_ai_features_v1` PostGIS view and reconciliation job. The legacy layers remain unchanged until authenticated catalog inspection and coordinated client testing are complete.

Public WMS/WFS capabilities were checked without credentials for the root endpoint and known workspaces. The endpoints responded, but did not advertise named WMS layers or WFS feature types. WFS Transaction was advertised at the service level, so access control must be verified before any writable feature type is exposed.

## Migration Guardrails

- Do not retire derived geospatial relations until live GeoServer layers are inspected.
- Inspect authenticated GeoServer stores, layers, SQL views, and data security before retiring no-reference geospatial relations.
- Do not keep every legacy scheduled command by default.
- Every retained job needs an owner, schedule, input source, output target, retry policy, idempotency rule, and test plan.
- Replace raw spatial filters with validated requests and parameterized PostGIS queries.
- Treat geospatial export/layer tables as rebuildable derived data whenever possible.

## Map Points Recovery Finding: 2026-08-12

Read-only host, GeoServer log, GeoWebCache catalog, and PostgreSQL inspection
isolated the active `mapilio:map_points` cache-miss failure. The layer uses a
`4 x 4` metatile and dense point requests exceed the configured 64 MiB MVT
metatile memory guard. GeoWebCache then exposes the internal failure as
`400 Problem communicating with GeoServer`. Cached tiles can continue to work,
which makes cache-hit-only smoke tests insufficient.

The PostGIS bounding-box query uses the expected GiST expression index, and a
representative single-tile direct WMS/MVT render returned successfully. The
approved repair shape is therefore to reduce only this layer's metatiling factor
to `1 x 1`, preserve the memory guard, and validate a real cache miss followed
by a cache hit. See the
[GeoServer map points recovery runbook](geoserver-map-points-recovery.md).

The controlled production application completed on the same date. The point
cache miss, subsequent cache hit, adjacent point tile, road control tile, and
mobile health probe all returned `200`; the observation interval produced no
matching GeoServer error or MVT memory-cap event. Rollback was not required.
