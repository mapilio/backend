# UKM PostGIS Index Plan

## Observed production snapshot

Read-only inspection on 2026-07-15 found approximately 19.4 million rows in `default_mapilio_imagery` and 24 indexes. Several geometry GiST and score indexes overlap in purpose. No existing index matches the geography expression used by the bounded UKM query.

`EXPLAIN` without execution showed:

- the legacy nearest-neighbor plan can walk the active geometry GiST index with an estimated candidate branch cost above 2.6 million while applying time, heading, and sequence rules as filters
- the bounded geography query, without a matching index, selected an unsuitable time-bearing index and had an estimated cost above 2.8 million

No production index or data was changed during this inspection.

## Proposed staging index

Run outside a transaction during an approved staging maintenance window:

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS ix_imagery_ukm_geography_active
ON public.default_mapilio_imagery
USING gist ((geom::geography))
WHERE deleted_at IS NULL
  AND anomaly IS FALSE
  AND geom IS NOT NULL;
```

Refresh relevant planner statistics after the build:

```sql
ANALYZE public.default_mapilio_imagery
    (geom, capture_time, heading, sequence_uuid, anomaly, deleted_at);
```

## Acceptance checks

1. Confirm the index is valid in `pg_index` and visible in `pg_indexes`.
2. Run `EXPLAIN (ANALYZE, BUFFERS)` in staging for representative recent and historical sequences.
3. Confirm the lateral candidate branch uses `ix_imagery_ukm_geography_active` and does not perform a sequential scan.
4. Record p50, p95, and maximum job duration plus rows read per source point.
5. Compare modern scores against the legacy formula and separately report no-neighbor corrections.
6. Measure insert/update overhead before considering any existing index removal.
7. Keep `MAPILIO_UKM_REQUIRE_SPATIAL_INDEX=true` during staging and production rollout.

## Rollback

Disable both UKM feature flags first. Then remove only the new index if needed:

```sql
DROP INDEX CONCURRENTLY IF EXISTS public.ix_imagery_ukm_geography_active;
```

Do not drop existing geometry or score indexes as part of this rollout.
