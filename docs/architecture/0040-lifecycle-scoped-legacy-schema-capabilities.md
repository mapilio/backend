# ADR 0040: Lifecycle-Scoped Legacy Schema Capabilities

- Status: Accepted
- Date: 2026-09-01

## Context

The legacy UKM scoring action and AI prediction dispatch action probe optional
legacy tables and columns while handling schemas that differ during migration.
Repeated `hasTable` and `hasColumn` calls can repeat database metadata work in a
single request or queue-job lifecycle. The useful boundary is deliberately
small: this decision covers only those two actions and their existing
fail-closed or fallback checks.

## Decision

Register `LegacySchemaCapabilities` with Laravel's scoped container binding.
The service does no database work when the provider registers or boots. On its
first request for a connection/table, it lazily reads table existence and, when
the table exists, the complete column-name listing. It stores the successful
snapshot under that connection/table pair and compares column names using
lowercase normalization, matching Laravel's schema builder behavior. A table
that is absent and a column that is absent are both cached as negative results.

The service exposes table checks, column checks, and filtering of values to
existing columns. All three operations use the same snapshot. Metadata read
exceptions are never cached, so a later operation can retry the read.

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

## Alternatives And Consequences

Persistent caching, TTLs, and configuration flags were rejected because schema
capabilities are deployment state, not application data. Persistent entries
could outlive a migration, leak across workers or connections, and make a
schema change require an explicit invalidation protocol. A scoped cache gives
the two bounded actions duplicate-read protection while framework lifecycle
cleanup supplies invalidation without boot-time database access or a production
schema assertion.
