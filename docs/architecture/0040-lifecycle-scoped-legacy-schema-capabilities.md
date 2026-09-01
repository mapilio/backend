# ADR 0040: Lifecycle-Scoped Legacy Schema Capabilities

- Status: Accepted
- Date: 2026-09-01

## Context

The legacy UKM scoring action, AI prediction dispatch action, mobile profile
query, imagery upload actions, and address-enrichment action probe optional
legacy tables and columns while handling schemas that differ during migration.
Repeated `hasTable` and
`hasColumn` calls can repeat database metadata work in a single request or
queue-job lifecycle. The useful boundary is deliberately small: this decision
covers those callers and their existing fail-closed or fallback checks.

## Decision

Register `LegacySchemaCapabilities` with Laravel's scoped container binding.
The service does no database work when the provider registers or boots. On its
first `hasTable` request for a connection/table, it reads and caches only table
existence, including a cached false result. `hasColumn` and
`filterExistingColumns` first reuse that existence result; only an existing
table triggers a lazy complete column-name listing. The successful column set
is cached separately under the same connection/table pair and compares column
names using lowercase normalization, matching Laravel's schema builder
behavior. A missing table never triggers column listing, and a missing column
is cached by the complete column set's negative membership.

The service exposes table checks, column checks, and filtering of values to
existing columns. Table existence and column sets are isolated by resolved
connection name. Metadata read exceptions are never cached. If column listing
fails after existence succeeds, the successful existence result remains safe to
reuse while the column set remains unpublished, so the next column operation
retries only column listing. A failed existence check is likewise retryable.

Laravel clears scoped instances between request and queue-job lifecycles. A new
scope therefore gets a new service and can observe a deployed schema change;
the old instance remains stable for the rest of its scope. Deployments must
still restart long-running queue workers after code or schema changes so code,
configuration, and database rollout remain coordinated.

## Verification Boundary

`LegacySchemaCapabilitiesTest` verifies the container's
`forgetScopedInstances()` reset primitive and that a fresh scoped resolution
sees a schema change. It does not construct a fake queue worker or claim an
end-to-end worker lifecycle test. Laravel's installed queue worker uses this
container primitive between jobs; deployments still restart workers after code
or schema changes.

## Preserved Behavior

UKM keeps its disabled early return before capability lookup. Missing UKM
tables still raise `UKM scoring tables are not available.` Missing sequence or
imagery columns keep their existing exact exceptions. UKM failure marking is
still best effort and only runs when its table and status columns exist. The
PostgreSQL spatial-index check, transactions, scoring queries, and result
envelope are unchanged.

AI prediction dispatch also keeps its disabled early return before capability
lookup. A missing `updated_at` column still skips stale-reservation expiry.
The optional project table and `config_url` column still control whether the
project URL is read; otherwise the configured AI fallback URL is used. The
existing filtering of insert and update values, reservation behavior,
transactions, HTTP request, job behavior, and error envelopes are unchanged.

Mobile profile keeps its user fields, default photo fallback, aggregate counts,
and response envelope unchanged. Missing optional aggregate tables still use
the existing zero-value fallbacks.

Imagery upload now injects the scoped service into both the upload and quality
scoring actions. The existing three-point upload contract measures one
table-existence capability lookup and one lazy complete-column snapshot across
geometry generation and quality scoring. In this locked framework version, that
snapshot is implemented as two SQLite SQL statements, pragma_table_xinfo and a
create-SQL sqlite_master read, for exactly three SQLite metadata statements in
total. The assertion filters only SQLite metadata statements on the legacy
connection, so application SQL is not part of that count. Upload response,
validation, transaction, idempotency, geometry, score calculations,
missing-column behavior, and queued jobs remain unchanged.

Address enrichment now uses the already-resolved legacy connection name with
one scoped filterExistingColumns call. The found path previously performed
four direct optional-column checks; not-found and error paths performed three.
The found-address test measures one scoped table-plus-column snapshot as exactly
three SQLite metadata statements in this locked framework version:
sqlite-master, column-listing, sqlite-master. This is a test measurement, not
a production-latency or deploy-time-completion claim.

Marketplace, one-shot, and different-table schema probes remain open and are
outside this verification boundary.

## Alternatives And Consequences

Persistent caching, TTLs, and configuration flags were rejected because schema
capabilities are deployment state, not application data. Persistent entries
could outlive a migration, leak across workers or connections, and make a
schema change require an explicit invalidation protocol. A scoped cache gives
these bounded callers duplicate-read protection while framework lifecycle
cleanup supplies invalidation without boot-time database access.
