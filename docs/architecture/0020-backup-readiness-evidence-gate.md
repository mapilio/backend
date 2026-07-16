# ADR 0020: Backup readiness evidence gate

## Status

Accepted for local implementation. Production backup infrastructure and policy thresholds have not yet been inspected or approved.

## Context

The legacy deployment template contains a nominal database backup step, but it invokes MySQL tooling for a PostgreSQL/PostGIS application, handles credentials through shell parsing, and provides no encryption, integrity, retention, off-site, immutability, PITR, or restore evidence. Copying this task would create false confidence and couple recoverability to deployment.

Mapilio data spans PostgreSQL/PostGIS, TrueNAS imagery, derived caches, GeoServer configuration, AI workflow state, and deployment secrets. Laravel cannot prove all those infrastructure controls by generating a SQL dump itself.

## Decision

Backup creation and restoration remain external infrastructure responsibilities. A new read-only Artisan command validates a strict, secret-free evidence manifest before release:

```bash
php artisan mapilio:verify-backup-readiness
```

The deployment must explicitly configure the expected environment, evidence path, maximum manifest/backup/WAL/restore-drill ages, and maximum measured RPO/RTO. No threshold is inferred by the command.

The schema requires successful PostgreSQL backup, artifact readability, checksum, encryption with externally held key, off-site immutable copy, PITR with fresh WAL, and a recent isolated restore drill that verifies PostGIS, migration state, data integrity, application boot, and measured objectives. Unknown fields and sensitive operational details are rejected.

## Consequences

The application now has a deterministic fail-closed release check without gaining backup-storage credentials or pretending to own infrastructure backups. Production remains blocked on selecting/configuring the actual backup system, approving policy objectives, generating evidence from provider-native checks, restoring into isolation, and adding the command before migrations in deployment automation.

Imagery, GeoServer, AI, caches, and secret-store recovery still require separate procedures and drills documented in `docs/operations/backup-and-restore.md`.
