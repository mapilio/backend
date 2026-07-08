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

Public WMS/WFS capabilities were checked without credentials for the root endpoint and known workspaces. The endpoints responded, but did not advertise named WMS layers or WFS feature types. WFS Transaction was advertised at the service level, so access control must be verified before any writable feature type is exposed.

## Migration Guardrails

- Do not retire derived geospatial relations until live GeoServer layers are inspected.
- Inspect authenticated GeoServer stores, layers, SQL views, and data security before retiring no-reference geospatial relations.
- Do not keep every legacy scheduled command by default.
- Every retained job needs an owner, schedule, input source, output target, retry policy, idempotency rule, and test plan.
- Replace raw spatial filters with validated requests and parameterized PostGIS queries.
- Treat geospatial export/layer tables as rebuildable derived data whenever possible.
