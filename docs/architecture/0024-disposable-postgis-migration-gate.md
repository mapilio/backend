# ADR 0024: Disposable PostgreSQL/PostGIS Migration Gate

## Status

Accepted.

## Context

SQLite catches migration ordering and portable schema regressions but cannot validate PostgreSQL generated columns, PostGIS geometry types, GiST indexes, extension prerequisites, or spatial functions. Running destructive migration tests against a shared or remotely configured database would be unacceptable.

## Decision

The Quality workflow provisions a fresh PostgreSQL 14/PostGIS service container for one isolated job. The image is pinned by OCI digest. It has no persistent volume, production data, Mapilio credential, or legacy database connection.

The wrapper refuses to start unless all disposable-connection invariants match exactly: explicit confirmation, the testing environment, PostgreSQL, loopback host, fixed port/database/user, disabled SSL for the local service, an empty `DB_URL`, an isolated legacy connection, and disabled local demo seeding. The integration test repeats the important connection assertions from inside Laravel before the first destructive command.

The test verifies:

- PostgreSQL major version 14 and an installed PostGIS extension;
- complete Laravel migration application;
- the generated `geometry(Point, 4326)` column expression;
- the GiST geometry index and versioned Geo view;
- generated SRID, type, coordinates, and validity for a synthetic point;
- complete rollback and clean migration re-application.

## Consequences

Every pushed revision now proves that the modern schema can run on the production database engine family without accessing production. The pinned image digest must be reviewed and deliberately updated when PostgreSQL/PostGIS patch versions change.

This gate is not a staging rehearsal. It does not measure locks, runtime on representative data, temporary disk, concurrent index strategy, production query plans, backup readiness, upgrade compatibility, or source-to-target backfill correctness. Those remain restricted release gates against an isolated representative staging copy.
