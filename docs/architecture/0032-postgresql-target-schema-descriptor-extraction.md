# ADR 0032: PostgreSQL target schema descriptor extraction

- Status: Accepted for restricted operator evidence
- Date: 2026-07-29

## Decision

Add a target-specific schema descriptor command separate from the source command. It is disabled by default, blocked in production, and requires an explicit read-only confirmation. Its fixed connection allowlist contains only the canonical `pgsql` name, which may also be the application default. The target endpoint is normalized using Laravel URL parsing and compared with `legacy_pgsql` configuration without resolving the legacy connection.

The target uses the injected, policy-neutral PostgreSQL catalog reader and endpoint normalizer shared with the source wrapper, plus the private atomic JSON publisher. Endpoint comparison is configuration identity (normalized host/port/database), including URL and structured-config overrides; it canonicalizes a trailing DNS dot but does not resolve DNS aliases. It emits exactly descriptor v1, with bounded read-only catalog queries and no row data.

## Limitations

Synthetic tests do not prove live target provenance, credentials, migration compatibility, mappings, reconciliation, or importer readiness.
