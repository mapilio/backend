# ADR 0002: Database Modernization Strategy

Date: 2026-07-08

## Decision

The modern backend may use a redesigned PostgreSQL/PostGIS schema instead of preserving the legacy database structure one-to-one.

The legacy database remains the source of truth during migration. Public API behavior, image URL compatibility, user identity mapping, AI callback semantics, GeoServer publication outputs, and community data access must be preserved through contract tests and validation.

## Context

The legacy database inspection showed a large, mixed-purpose schema:

- PostgreSQL 14 with PostGIS enabled
- 214 public base tables
- 400 public indexes
- approximately 19.4 million rows and 43 GB total size in the primary imagery table
- large segmentation, bbox, location, relation, feature, measurement, operational, old, and backup tables
- several geospatial columns with SRID `0`

This shape is useful history, but it is not automatically the target model for the new backend.

## Consequences

- Laravel migrations define the new canonical schema.
- Legacy-to-modern import commands must be repeatable, idempotent, and testable.
- Old IDs that remain externally visible need explicit mapping tables or compatibility columns.
- Staging backfills must compare row counts, aggregates, geospatial extents, API responses, and selected sample records before cutover.
- High-volume tables may be split by domain, indexed differently, partitioned, or represented by rollups/materialized views when query evidence supports it.
- Operational data such as logs, metrics, failed jobs, and debug tables should have retention policies and should not be treated as permanent product data by default.

## Migration Shape

1. Classify every legacy table as core, derived, operational, archived, retired, or owner-decision-needed.
2. Design target tables around Mapilio domains: identity/access, imagery/sequences, AI jobs/predictions, inventory features, geospatial publishing, operations, and community integrations.
3. Build import commands that copy from legacy sources into the new schema and record source-to-target mappings.
4. Run staging backfills from a recent production snapshot or safe read-only source.
5. Compare old and new preserved API behavior endpoint by endpoint.
6. Use incremental sync, watermarks, or logical replication for domains still changing before production cutover.
7. Move traffic route-by-route with rollback and keep the legacy database read-only during the rollback window.

## Guardrails

- Do not directly rewrite the production schema as the first migration step.
- Do not publish row contents, image paths, tokens, or user data in public documentation.
- Do not keep generic CMS tables in the new runtime unless an active Mapilio workflow requires them.
- Do not change existing public API response shapes without a versioned API and client migration plan.
- Do not migrate write endpoints until validation, idempotency, audit logging, and rollback are in place.
