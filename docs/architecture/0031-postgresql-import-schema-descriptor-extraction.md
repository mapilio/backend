# ADR 0031: PostgreSQL import schema descriptor extraction

- Status: Accepted for restricted operator evidence; real provenance remains required
- Date: 2026-07-29

## Decision

Provide a separate, disabled-by-default `mapilio:extract-import-schema` command for one configured PostgreSQL source schema/table. The source policy is a fixed application-configured allowlist containing only `legacy_pgsql`; it is not environment-expandable. That connection must use `pgsql`, have an unambiguous endpoint, and must not equal the configured default/application PostgreSQL endpoint. Production is rejected before connection or output work. Schema/table names are configured lowercase identifiers; system schemas (`pg_catalog`, `information_schema`, and `pg_toast*`) are rejected.

The command establishes a bounded `PGCONNECT_TIMEOUT`, restores its exact prior value on success and failure, starts a read-only transaction, verifies `transaction_read_only=on`, and applies local statement/lock timeouts before each metadata read. It verifies an ordinary `pg_class` base table and matches the complete visible `pg_attribute` count to ordered `information_schema.columns` metadata. Malformed catalog values, gaps, duplicates, domains, arrays, user-defined/generated/identity columns, and non-`pg_catalog` or unsupported v1 types fail closed.

The result has exactly descriptor v1 keys and is compact JSON capped at the fingerprint contract’s 256 KiB limit. It is published under an owner-private 0700 directory as a 0600 file with same-directory atomic no-overwrite hard-link publication. The publication guarantee assumes an owner-controlled parent that is not renamed or replaced during the operation; hostile same-UID races are outside the boundary. Post-link verification failures remove the destination only after verifying its identity is the file created by this run. Once the destination passes identity and privacy verification, temporary-link cleanup is best effort and cannot change the successful publication result.

## Limitations

This extracts metadata only. It does not prove credential ownership, source provenance, target compatibility, mappings, approvals, row safety, reconciliation, or importer readiness. It deliberately excludes PostgreSQL domains, arrays, custom types, generated columns, and identity columns from descriptor v1. No production, network, or live-database test is part of the repository test suite.
