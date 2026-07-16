# Backup and Restore Operations

## Boundary

The Laravel application does not create, upload, encrypt, retain, or delete infrastructure backups. PostgreSQL/PostGIS backups, WAL archiving, TrueNAS imagery snapshots, GeoServer configuration exports, and deployment-secret recovery belong to separately permissioned infrastructure tooling.

The application provides one read-only release gate:

```bash
php artisan mapilio:verify-backup-readiness
```

The command validates a small JSON evidence manifest produced after the external backup checks. It never connects to backup storage, starts a backup, restores data, changes retention, or prints evidence values. A passing manifest is evidence that the configured checks were reported recently; it is not a replacement for provider logs, immutable audit records, restore observation, or operator approval.

## Legacy Finding

The legacy deploy file contains an optional `deployment_backup` task, but the production application uses PostgreSQL while that task invokes `mysqldump`. It also extracts credentials from `.env` through shell commands and writes an unencrypted `database.sql` into the current release directory without a checksum, defined destination, retention, off-site copy, immutability, WAL archive, or restore verification.

Do not port or run that task. Deployment and backup must be independent failure domains, and a failed backup gate must stop a release before migrations begin.

## Required Coverage

The infrastructure design must cover each data class independently:

| Data class | Required recovery control |
| --- | --- |
| Modern PostgreSQL/PostGIS | Encrypted full/base backup, continuous WAL archiving for PITR, checksum/read verification, off-site immutable copy, and isolated restore drills. |
| Legacy PostgreSQL/PostGIS during migration | Read-only source protection, independently tested backup/PITR, and a documented cutover rollback point. |
| Original and derived imagery on TrueNAS | Snapshot/replication policy, off-site or fault-domain-separated copy, anonymization-state integrity, and representative file restore tests. |
| Image cache on NVMe | Rebuild procedure and cache invalidation; cache is derived and must not be the only copy. |
| GeoServer | Catalog, workspace, store, layer, style, security, and cache configuration export plus reconstruction test. |
| AI and queue state | Database-backed durable state recovery, idempotent replay rules, and quarantine of ambiguous in-flight work. |
| Deployment configuration and secrets | Encrypted secret-store recovery with separate access, rotation, and emergency ownership. Secrets never belong in the evidence manifest. |

## Evidence Manifest

The file path and all policy thresholds must be explicitly configured:

```dotenv
MAPILIO_BACKUP_EVIDENCE_PATH=/run/mapilio/backup-readiness.json
MAPILIO_BACKUP_EXPECTED_ENVIRONMENT=production
MAPILIO_BACKUP_MAX_MANIFEST_AGE_MINUTES=15
MAPILIO_BACKUP_MAX_AGE_HOURS=24
MAPILIO_BACKUP_MAX_WAL_AGE_MINUTES=15
MAPILIO_BACKUP_MAX_RESTORE_DRILL_AGE_DAYS=90
MAPILIO_BACKUP_MAX_RPO_SECONDS=900
MAPILIO_BACKUP_MAX_RTO_SECONDS=14400
```

The numbers above illustrate the format only. Service owners must approve real RPO, RTO, backup frequency, WAL lag, and restore-drill cadence from business and infrastructure requirements. Blank, zero, negative, malformed, stale, future-dated, or mismatched values fail closed.

The external verifier writes a JSON object using exactly this public, secret-free schema. The machine-readable contract is available at [`backup-readiness-evidence.schema.json`](backup-readiness-evidence.schema.json).

```json
{
  "schema_version": 1,
  "environment": "production",
  "generated_at": "2030-01-15T10:00:00Z",
  "database": {
    "engine": "postgresql",
    "status": "success",
    "completed_at": "2030-01-15T09:30:00Z",
    "artifact_read_verified": true,
    "checksum_verified": true,
    "encrypted": true,
    "encryption_key_external": true,
    "offsite_copy_verified": true,
    "immutable_copy_verified": true,
    "pitr_enabled": true,
    "latest_wal_archived_at": "2030-01-15T09:58:00Z"
  },
  "restore_drill": {
    "status": "success",
    "completed_at": "2030-01-10T12:00:00Z",
    "target": "isolated-non-production",
    "postgis_verified": true,
    "migration_state_verified": true,
    "integrity_checks_verified": true,
    "application_boot_verified": true,
    "measured_rpo_seconds": 300,
    "measured_rto_seconds": 3600
  }
}
```

Use only UTC timestamps. The strict allowlist deliberately rejects extra fields so credentials, hosts, bucket names, database names, storage paths, user data, or imagery identifiers cannot drift into command output or repository examples.

Write the manifest atomically with permissions limited to the deployment operator and application release user. Store the authoritative evidence, artifact checksums, backup identifiers, provider logs, restore logs, and approvals in restricted operational storage rather than this public repository.

## Restore Drill

1. Select an isolated non-production target with no routes to production queues, email, AI callbacks, GeoServer publication, image writes, or external webhooks.
2. Record the requested recovery point and start time in restricted evidence storage.
3. Restore the database and required WAL to the selected point using infrastructure-native tooling.
4. Verify PostgreSQL and PostGIS versions/extensions, migration state, spatial validity, ownership boundaries, critical row/aggregate checks, and backup checksum provenance.
5. Boot the matching backend revision with all side effects disabled and run health plus representative read-only API checks.
6. Measure achieved RPO and RTO. A test that exceeds policy is a failed readiness gate even when the database eventually starts.
7. Destroy or securely retain the isolated copy according to the approved privacy and retention decision.
8. Produce the secret-free manifest only after the restricted evidence is complete and independently reviewed.

## Release and Incident Use

Run the command before database migrations and releases that depend on recoverability. Do not make the public health endpoint expose backup age, WAL lag, storage identifiers, or restore details.

During an incident, preserve backup evidence before rotation or cleanup, then follow [the incident response runbook](../security/incident-response.md). A green readiness check does not authorize a restore; the incident commander and database owner choose the recovery point and containment sequence.
