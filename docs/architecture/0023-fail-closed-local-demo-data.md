# ADR 0023: Fail-Closed Local Demo Data

## Status

Accepted.

## Context

The modern backend needs repeatable migration and API examples for contributors without copying production data or recreating PyroCMS tables. A conventional default user is misleading because the modern admin boundary is not implemented, and a permissive seeder could accidentally write to a remote database.

SQLite is sufficient for migration lifecycle checks and a small canonical AI feature fixture. It does not prove PostgreSQL/PostGIS behavior.

## Decision

- `DatabaseSeeder` creates only synthetic modern-schema fixtures.
- No user, credential, legacy table, imagery record, or external-service identifier is seeded.
- Seeding requires `MAPILIO_DEMO_SEEDING_ENABLED=true` after configuration parsing.
- The application environment must be `local` or `testing`.
- The active database driver must be SQLite.
- Every condition is checked immediately before writes and malformed values fail closed.
- The seed is transactional and idempotent.
- The synthetic callback body is encrypted with the application encrypter before storage.
- A fixed feature id supports a deterministic public API example.

## Consequences

Contributors can rebuild a throwaway local database and exercise a real versioned endpoint without production data. The seed cannot be used against local PostgreSQL, staging, or production; richer PostgreSQL fixtures require a separate disposable-database design and explicit safeguards.

The SQLite suite validates migration ordering, rollback, constraints, the compatibility view, seed idempotency, encryption, and the API response. A disposable PostgreSQL 14 plus PostGIS migration run remains required before deployment because SQLite cannot validate generated geometry, GiST indexes, extension availability, or PostgreSQL query plans.
