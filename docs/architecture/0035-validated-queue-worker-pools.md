# ADR 0035: Validated Queue Worker Pools

- Status: Accepted for source-controlled staging preparation
- Date: 2026-07-29

## Context

The queue timing boundary already rejects retry or visibility windows below 660 seconds, while the longest current job timeout is 600 seconds. The runtime runbook described four isolated worker pools and a minimum 720-second graceful stop, but those values existed only as prose and shell examples. A process-manager typo could combine queues, shorten shutdown, omit worker recycling, or silently let expensive spatial work starve callback processing.

## Decision

Define the four worker pools in `config/queue-workers.php` and validate them at application boot:

- `callbacks-results`
- `projections-publication`
- `outbound-enrichment`
- `ukm-scoring`

Queue names resolve from their existing `config/mapilio.php` keys, so environment-approved queue aliases remain supported without copying them into process-manager files. Queue names must be bounded safe identifiers, and one queue cannot belong to multiple pools.

Every pool uses a 600-second worker timeout, one-second idle sleep, 3,600-second maximum process lifetime, and 1,000-job maximum. The default memory limit is 512 MiB; UKM scoring uses 1,024 MiB. Configuration fails closed when values are malformed, unknown fields are present, queues overlap, or graceful stop is below 720 seconds.

`php artisan mapilio:queue-work <pool>` is the only source-controlled process-manager entry point. It validates the complete plan before delegating to Laravel's `queue:work`. `--dry-run` prints only pool names, queue names, connection name, and numeric limits.

The public Supervisor example starts one process per pool, automatically replaces clean max-time/max-job exits, signals the complete process group, and waits 720 seconds before forced termination. The release path and application user remain deployment-owned placeholders. Secrets and completed staging evidence are not committed.

## Consequences

Pool membership and worker lifecycle limits now receive application, test, review, and configuration-cache coverage. The staging baseline deliberately uses one process per pool. Concurrency changes require measured arrival rate, queue age, completion latency, memory, PostgreSQL load, and external-service capacity.

This source slice does not prove Supervisor installation, queue backend durability, shared unique locks, crash/redelivery behavior, graceful replacement, provider visibility, alerts, or safe external side effects. Those require an isolated staging deployment with related feature flags disabled until each workflow is explicitly exercised.
