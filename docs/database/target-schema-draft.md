# Target Schema Draft

Date: 2026-07-08

This draft describes the first clean PostgreSQL/PostGIS schema direction for the modern Mapilio backend. It is intentionally not a one-to-one copy of the legacy PyroCMS database.

## Design Rules

- Preserve public API behavior and externally visible identifiers.
- Prefer explicit Mapilio domain tables over generated CMS table structures.
- Keep high-volume imagery, AI, and geospatial data queryable without joining through CMS metadata tables.
- Use PostGIS columns with explicit SRID rules.
- Store stable public identifiers separately from storage-optimized primary keys where needed.
- Keep legacy source-to-target mappings during migration and rollback windows.
- Treat operational logs, metrics, failed jobs, and debug data as bounded operational data, not permanent product data.

## Identifier Strategy

High-volume tables should use `bigint` primary keys for storage and index efficiency. Public-facing resources can also carry `ulid` or `uuid` columns when stable opaque identifiers are needed by clients.

During migration, keep a mapping table:

| Column | Purpose |
| --- | --- |
| `legacy_table` | Original source relation name or source domain key. |
| `legacy_id` | Original primary key or stable source identifier. |
| `target_table` | New canonical table name. |
| `target_id` | New primary key. |
| `migrated_at` | Import timestamp. |
| `source_checksum` | Optional row/content checksum for validation. |

## Core Tables

### Identity And Access

| Table | Purpose |
| --- | --- |
| `users` | Mapilio user accounts required by APIs, mobile, web, and admin. |
| `user_profiles` | Non-auth profile fields and public display metadata. |
| `roles` | Admin and platform roles. |
| `role_user` | User-role assignments. |
| `api_clients` | Web, mobile, AI, OSM/community, and internal service clients. |
| `access_tokens` | Hashed API tokens or OAuth/Sanctum-compatible token records. |
| `audit_logs` | Security-sensitive admin and API actions. |

### Imagery And Sequences

| Table | Purpose |
| --- | --- |
| `upload_batches` | Upload sessions/import batches from web, mobile, or tooling. |
| `images` | Canonical image metadata: owner, capture time, heading, status, visibility, source, and public identifier. |
| `image_assets` | Original and derived asset paths known by the image server, including blur/cache state where needed. |
| `image_positions` | Current normalized position for image lookup, using `geometry(Point, 4326)` or `geography(Point, 4326)` after query testing. |
| `sequences` | Logical drive/walk/capture sequences. |
| `sequence_images` | Ordered image membership in a sequence. |
| `image_processing_states` | Blur, upload, AI, GeoServer, and publication state per image. |

### AI Jobs And Predictions

| Table | Purpose |
| --- | --- |
| `ai_jobs` | Backend-to-AI requests with status, retries, idempotency key, and callback state. |
| `prediction_runs` | One model/version/run result set for a job or image batch. |
| `detection_features` | Lightweight detected inventory feature rows with type, confidence, image, position, and publication state. |
| `feature_boxes` | Bounding boxes separated from feature rows for faster feature listing. |
| `feature_segments` | Segmentation payloads or references, isolated from lightweight reads. |
| `feature_measurements` | Measurements attached to detected features. |
| `feature_relations` | Relationships between detections, images, and derived outputs. |

### Geo Publishing

| Table | Purpose |
| --- | --- |
| `roads` | Canonical road or captured path geometries where Mapilio owns the derived data. |
| `coverage_cells` | Grid/coverage rollups for fast map rendering and statistics. |
| `geo_layers` | Published layer definitions and visibility policy. |
| `geoserver_publications` | GeoServer workspace/layer publication state, errors, and timestamps. |
| `country_image_counts` | Precomputed country-level image counts for compatibility and fast public reads. |

### Operations

| Table | Purpose |
| --- | --- |
| `import_runs` | Legacy-to-modern import run metadata and validation summaries. |
| `import_rejections` | Rows skipped by import validation, with safe reason codes. |
| `webhook_events` | AI callbacks and external webhook deliveries with signature and replay status. |
| `jobs` | Queue jobs managed by Laravel. |
| `failed_jobs` | Failed queue jobs with retention policy. |
| `system_metric_rollups` | Bounded operational rollups for dashboard health screens. |

## Derived Data

Derived tables should be rebuildable from canonical tables or source systems. Candidate derived data:

- leaderboard totals
- country image counts
- coverage cells
- GeoServer export tables
- public map feature layers
- dashboard metric rollups

Derived data should have a named rebuild command, freshness target, and owner before it is treated as production-critical.

## Index Direction

Indexes must be confirmed with real query plans before production rollout.

| Area | Initial Direction |
| --- | --- |
| Imagery lists | Composite indexes by owner/status/capture time and sequence membership. |
| Public map viewport reads | Spatial index on normalized image positions, plus status/visibility filter indexes. |
| AI feature reads | Indexes by image, prediction run, feature type, confidence, and publication state. |
| Leaderboards | Rollup tables or materialized views instead of repeated correlated scoring queries. |
| GeoServer layers | Spatial indexes on published geometry tables and explicit publication status filters. |
| Operations | Retention-aware indexes by status, queue, created time, and failure type. |

## Open Questions

- Which legacy access-token records are still used by active mobile, web, AI, and community clients?
- Which geospatial derived tables are authoritative versus cached exports?
- Which optional admin/business modules are still active workflows?
- Which image-server asset path fields are required to keep old URLs stable?
- Which AI result payloads must remain queryable in PostgreSQL, and which can be stored as external artifacts with indexed summaries?
