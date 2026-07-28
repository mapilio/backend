# Legacy import preflight

`mapilio:legacy-import-preflight` is a read-only evidence gate. It is not an importer and does not copy, transform, update, delete, or otherwise write database data.

It is disabled by default. To run a synthetic local/testing check, configure an explicitly named SQLite connection, enable the feature, and provide a comma-separated allowlist of simple table identifiers:

```dotenv
MAPILIO_LEGACY_IMPORT_PREFLIGHT_ENABLED=true
MAPILIO_LEGACY_IMPORT_PREFLIGHT_TABLES=legacy_users,legacy_tracks
MAPILIO_LEGACY_IMPORT_PREFLIGHT_OUTPUT_DIRECTORY=/private/evidence/directory
MAPILIO_LEGACY_IMPORT_PREFLIGHT_CONNECT_TIMEOUT_SECONDS=5
MAPILIO_LEGACY_IMPORT_PREFLIGHT_STATEMENT_TIMEOUT_MS=5000
MAPILIO_LEGACY_IMPORT_PREFLIGHT_LOCK_TIMEOUT_MS=1000
MAPILIO_LEGACY_IMPORT_PREFLIGHT_MAX_RUNTIME_MS=30000
MAPILIO_LEGACY_DB_CONNECTION=legacy_synthetic
```

Run it with a new JSON basename:

```shell
php artisan mapilio:legacy-import-preflight \
  --output=legacy-preflight.json \
  --confirm-read-only-source
```

The command only permits `local`, `testing`, and `staging`. SQLite is limited to local/testing synthetic use. PostgreSQL is permitted in local/testing/staging, but the action begins a transaction, sets transaction-level read-only mode, verifies `transaction_read_only=on`, and performs all inspection inside that transaction. No session-wide PostgreSQL state is changed, and no connection override is accepted by the command.

The allowlist is limited to 50 unique, lowercase simple identifiers. Each table is checked for existence, column count, and aggregate row count. No samples, row values, coordinates, email addresses, or hashes are read or emitted. The output directory is created as a 0700 owner-private directory when absent and must remain non-symlinked and owner-private; the manifest is published as a 0600 regular file. The caller cannot provide a path, traversal, separator, uppercase name, unsafe extension, symlink, or existing target.

For PostgreSQL, the libpq connection-establishment guard defaults to 5 seconds and is bounded to 1–30 seconds. It temporarily sets `PGCONNECT_TIMEOUT` around the Laravel `DatabaseManager->connection` call and an immediate `getPdo()` call to force physical PDO/libpq resolution, restores the exact prior process environment value immediately afterward, and starts the transaction only after restoration. This is only a connection-establishment guard; PHP cannot interrupt a resolver or an already-cached connection beyond libpq's supported timeout semantics. The default transaction-local `statement_timeout` is 5000 ms, `lock_timeout` is 1000 ms, and the overall inspection budget is 30000 ms. Configuration bounds are 100–60000 ms, 100–10000 ms, and 1000–120000 ms respectively. The action issues `SET TRANSACTION READ ONLY`, installs local timeouts before verifying `transaction_read_only=on`, and recalculates remaining monotonic budget plus local statement/lock timeouts before every subsequent SELECT. No statement is configured beyond the remaining overall budget, and no session-wide state is changed.

The JSON is encoded before publication and written completely to a random same-directory 0600 temporary file, flushed and fsynced when available, then atomically published without overwrite by creating a hard link from the temporary file to the final path. If same-directory hard links are unavailable, or the destination appears first, publication fails closed. The directory identity is reverified immediately before linking, and final/temp device-inode identity is checked. Temporary files are removed only when their non-null identity still matches the file created by this run.

The output directory must be exclusively controlled by the invoking OS user. Hostile same-UID processes are outside this PHP command's protection boundary; the hard-link publication provides atomic no-overwrite behavior within that trust boundary.

## Manifest shape

The restricted manifest contains only this shape (the timestamp and run ID vary):

```json
{
  "schema_version": 1,
  "generated_at": "2026-07-28T00:00:00Z",
  "run_id": "00000000-0000-4000-8000-000000000000",
  "environment_class": "testing",
  "driver": "sqlite",
  "connection_name": "legacy_synthetic",
  "tables": [
    {
      "table": "legacy_users",
      "exists": true,
      "column_count": 2,
      "row_count": 2,
      "status": "PASS",
      "reason_code": "OK"
    },
    {
      "table": "legacy_tracks",
      "exists": true,
      "column_count": 3,
      "row_count": 1,
      "status": "PASS",
      "reason_code": "OK"
    }
  ]
}
```

Evidence is restricted operational output and must not be committed, uploaded, or placed under a public directory. Console output contains only bounded check labels and reason codes; it never prints table names, counts, paths, SQL, or exception details.

Stable failure codes include `PREFLIGHT_NOT_ENABLED`, `PRODUCTION_BLOCKED`, `CONFIRMATION_REQUIRED`, `TABLE_ALLOWLIST_EMPTY`, `TABLE_ALLOWLIST_INVALID`, `CONNECTION_NOT_ALLOWED`, `READ_ONLY_UNVERIFIED`, `TABLE_MISSING`, `QUERY_FAILED`, `OUTPUT_INVALID`, `OUTPUT_EXISTS`, and `MANIFEST_WRITE_FAILED`.

The PostgreSQL test uses mocks and synthetic values to verify SQL ordering, configured timeout reduction, environment restoration, and sanitization only. It does not prove real PostgreSQL, libpq, transaction read-only, or timeout behavior. This slice does not authorize an importer. Before any importer work, the owner must run a restricted isolated PostgreSQL/staging preflight and approve the target schema mapping, retention and evidence policy, rollback/restore plan, source credentials and least-privilege grant, representative data-quality results, and an explicit production change gate. No remote PostgreSQL or network service is used by the automated tests.
