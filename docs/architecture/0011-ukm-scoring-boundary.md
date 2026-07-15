# ADR 0011: UKM Scoring Boundary

## Status

Accepted for local implementation and staging planning. Disabled by default. The required production spatial index is not installed.

## Context

The active legacy UKM workflow creates one sequence job and then one queue job per imagery row. Every imagery job runs a raw interpolated PostGIS nearest-neighbor query across historical imagery. The resulting `ukm_score` is consumed by version 2 leaderboards.

The legacy rule considers active imagery from another sequence, captured during the preceding six months, with a circular heading difference of at most 45 degrees. Nearest distance is clamped to 1-40 metres, converted to a 0.1-5 score, and divided by a minimum-one-year age factor.

The production imagery table has approximately 19.4 million rows and 24 indexes. The existing query uses geometry KNN ordering, but its date, heading, and sequence predicates remain filters over a potentially large index walk. A geography-radius proposal without a matching expression index also produces an unsafe plan.

## Decision

`CalculateSequenceUkmScores` calculates all pending imagery for one sequence in one job:

- one set-based PostgreSQL lateral query finds nearest qualifying historical imagery
- `ST_DWithin(...::geography, ...::geography, 40)` bounds the search to the only range that can affect the score
- portable Haversine logic provides deterministic SQLite tests
- PostgreSQL results are persisted in parameterized batches of 500 rows
- existing non-null scores are not overwritten
- compatible imagery statuses remain `1` error and `2` completed
- one invalid pending imagery row fails the sequence visibly instead of being silently skipped
- sequence jobs are unique and retryable

Automatic post-upload dispatch requires both UKM feature flags. Both are disabled by default.

## No-neighbor correction

The legacy code assigns its minimum score when no historical candidate exists, even though a point farther than 40 metres receives the maximum uniqueness score. The modern rule treats no qualifying historical neighbor within 40 metres as fully unique and assigns the maximum distance/score. This semantic correction must be product-approved against staging score distributions before activation.

## Fail-closed index gate

PostgreSQL execution requires the configured `ix_imagery_ukm_geography_active` index by default. If the index is absent, the job fails before running the spatial query. The index must be installed concurrently and its plan measured in staging according to `docs/database/ukm-postgis-index-plan.md`.

Existing duplicate-looking geometry and score indexes are not removed by this change. Index consolidation needs usage statistics, write-amplification measurements, dependency checks, and a separate rollback plan.

## Deferred work

- install and benchmark the geography expression index in staging
- compare modern and legacy score distributions, including no-neighbor cases
- obtain product approval for the no-neighbor correction
- benchmark representative small, medium, and large sequences
- add UKM duration, candidate-hit, no-neighbor, and failure metrics
- decide whether scores should decay/recalculate as imagery ages
- enable post-upload dispatch only after staging acceptance
