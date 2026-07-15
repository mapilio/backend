# ADR 0009: Canonical AI Detection Persistence

## Status

Accepted for local and staging integration. Disabled by default. Legacy-table projection and GeoServer publication are not enabled.

## Context

The legacy prediction job expands one AI callback into six PyroCMS stream tables for features, measurements, bounding boxes, relations, locations, and segmentation. It also builds PostGIS expressions from callback values and can leave a partially written graph when a later record fails.

The modern backend needs a smaller domain model, strict ownership checks, deterministic retries, and an atomic boundary before any AI result can become platform data.

## Decision

Validated callback receipts are normalized into three canonical tables:

- `ai_detection_features`: one classified detection from an AI response
- `ai_detection_observations`: reusable imagery observations and their measurement, bbox, location, segmentation, and attributes
- `ai_detection_matches`: the ordered relationship between a feature and an observation, including match confidence

This structure preserves the useful detection graph without recreating the PyroCMS stream layout.

Before any write, the persistence action validates the complete result:

- the response id belongs to exactly one active legacy processing request
- the receipt response id and status match its encrypted payload
- every class code exists in the active legacy type allowlist
- every imagery id is active, non-anomalous, and belongs to the processing sequence
- GeoJSON values are Points with finite longitude and latitude
- confidence and score values are within their allowed ranges
- bounding boxes are finite, ordered, and bounded
- match counts, segmentation, and attribute payload sizes stay within configured limits
- repeated object keys cannot describe conflicting observations

Persistence locks the receipt, validates before writing, and stores the full feature graph in one modern-database transaction. A processed receipt is idempotent and does not create duplicate records. A failed result stores a safe error on the receipt and does not leave a partial graph.

`ValidatePredictionCallbackReceipt` queues the result job only when `MAPILIO_AI_RESULT_PERSISTENCE_ENABLED=true`. Callback receipt ingestion and result persistence remain separate feature flags, both disabled by default.

## Geometry strategy

Canonical latitude, longitude, and GeoJSON are stored now so the contract can be tested with SQLite locally. PostgreSQL/PostGIS geometry columns, spatial indexes, and parameterized geometry backfill will be added and benchmarked in staging before geospatial cutover.

## Deferred work

- project canonical detections into legacy tables only if an active consumer still requires them
- project processing and sequence status changes through a durable, observable job
- publish approved detections to GeoServer with reconciliation checks
- add operator quarantine, retry controls, audit events, and metrics
- calibrate payload and graph limits against representative staging results
- add PostgreSQL/PostGIS geometry columns and spatial indexes
