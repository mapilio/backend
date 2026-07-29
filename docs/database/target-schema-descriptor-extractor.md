# PostgreSQL target schema descriptor extractor

`mapilio:extract-target-schema` is a disabled-by-default operator command for the configured modern PostgreSQL target. It is available only in local, testing, and staging environments and requires `--confirm-read-only-target`. The fixed connection policy accepts only the canonical `pgsql` connection; it never resolves the legacy connection and refuses an endpoint collision with `legacy_pgsql`.

Configure `MAPILIO_TARGET_SCHEMA_SCHEMA`, `MAPILIO_TARGET_SCHEMA_TABLE`, and the separate private output directory, then run with `--output=target.json`. The command uses the same descriptor v1 shape and private atomic publisher as the source extractor, so existing fingerprint tooling consumes it unchanged.

Endpoint comparison is configuration identity only: normalized host, port, and database are compared after URL/structured-config parsing. It canonicalizes host case and a trailing DNS dot; it does not perform DNS alias resolution.

The catalog reader starts a read-only transaction, verifies `transaction_read_only=on`, and applies bounded connection, lock, statement, and total-runtime timeouts. It reads only catalog metadata and never row data. Tests use mocked connections; no live database or network is required.
