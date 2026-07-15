# ADR 0006: Post-upload Quality Scoring

## Status

Accepted for the compatibility bridge. Staging validation remains required.

## Context

The legacy upload worker launches separate jobs for GPS, capture-time, sequence-distance, and UKM scoring. Three of those jobs contain sequence UUIDs in raw SQL and depend on PostgreSQL-specific expressions even though their business rules are small.

The modern backend needs deterministic scores immediately after imagery metadata is accepted, while preserving the values consumed by the existing leaderboard APIs.

## Decision

`CalculateSequenceQualityScores` owns GPS, capture-time, and sequence-distance scoring for one sequence.

- GPS score remains `3` for accuracy up to 5 metres, `2` up to 10 metres or missing accuracy, and `1` above 10 metres.
- Capture-time score remains `1` from 07:00 through 20:59 and `0` otherwise.
- Distance score uses the nearest later point with a compatible heading and keeps the legacy normalized values `1.0`, `0.8`, `0.6`, `0.4`, or `0.2`.
- Deleted and anomalous imagery are excluded.
- Existing non-null scores are not overwritten, making upload retries idempotent.
- Distance is calculated with a Haversine implementation so the rule can be tested without PostGIS. Production PostGIS remains the authoritative geometry store.

The action runs inside the metadata transaction for the current compatibility bridge. Moving all post-upload processing to a durable queue is a later cutover gate and must include visible failure state, retries, and idempotency.

## Deferred work

UKM scoring is intentionally separate because it compares each image with historical imagery from other sequences over a six-month window. Its data-access plan and required geospatial indexes must be validated against staging PostgreSQL before implementation.

Address lookup and AI prediction dispatch now have separate disabled-by-default queue boundaries. UKM scoring, GeoServer publication, and image-server end-to-end staging tests remain downstream gates.
