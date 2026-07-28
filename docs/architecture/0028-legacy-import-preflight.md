# ADR 0028: Legacy import preflight evidence

- Status: Accepted for local synthetic verification; isolated PostgreSQL/staging evidence required
- Date: 2026-07-28

## Context

The completed slice provides a bounded read-only preflight for legacy database evidence. It is **not** approval for an importer, backfill, or source-to-target mapping.

## Decision

- Keep the command disabled by default and limited to local, testing, and staging, with explicit confirmation and a configured source connection only.
- Allow only an explicit allowlist of lowercase simple table names, at most 50. SQLite is synthetic-only; PostgreSQL uses a read-only transaction with bounded physical connect, statement, lock, and overall timeouts.
- Collect schema metadata and aggregate row counts only. Never collect samples, row values, coordinates, emails, or hashes.
- Write evidence under a restricted 0700 directory with 0600 temporary/final files. Publish atomically, without overwrite, using a same-directory hard link; fail closed on unsupported filesystems. The trust boundary is same-UID processes.
- Expose only safe reason codes and bounded console labels; never expose credentials, paths, SQL, or exception details.

The slice has 14 synthetic/mocked tests and independent security review. It has no real PostgreSQL or remote-access evidence.

## Consequences

This creates a restricted, non-disclosing evidence foundation without authorizing writes or migration. Owner approval remains required for source-to-target mapping and PII policy, importer idempotency/rejections/reconciliation, backup/rollback, representative staging, and the production change gate. Those decisions are deferred.
