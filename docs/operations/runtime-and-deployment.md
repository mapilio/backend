# Runtime and Deployment Operations

## Scope

This runbook describes the current modern backend runtime. It is service-manager neutral: systemd, Supervisor, containers, or an orchestrator may own processes, but they must preserve the same queue, shutdown, cache, logging, deployment, and rollback invariants.

Do not place production credentials, internal hostnames, user data, imagery identifiers, database samples, or completed release evidence in this public document. Environment-specific commands, approvals, measurements, and artifact digests belong in restricted operations records.

The [release readiness checklist](release-readiness.md) remains the go/no-go control. This runbook explains how to operate the backend; it does not authorize production deployment.

## Runtime Processes

The backend needs these process classes when their related features are active:

| Process | Required behavior |
| --- | --- |
| PHP web runtime | Serve the immutable release through the deployment-owned reverse proxy; only the public directory is web-accessible. |
| Queue workers | Long-running `queue:work` processes split into the pools below, supervised and automatically restarted. |
| Scheduler | No application schedules are currently registered. Do not claim scheduler coverage until `php artisan schedule:list` shows reviewed tasks. |
| Asset serving | Serve the CI-built Vite manifest/assets from the immutable release or approved CDN artifact. Do not run a development Vite server in production. |
| Log shipping | Capture application, PHP runtime, reverse-proxy, process-manager, and queue failure events with restricted access and retention. |

Laravel Pail, `queue:listen`, `schedule:work`, and the Composer `dev` script are development tools. They are not production process definitions.

## Queue Inventory

All queue names are configurable, but the defaults below are part of the current deployment contract.

| Default queue | Job | Timeout | Tries | Duplicate control |
| --- | --- | ---: | ---: | --- |
| `ai-callbacks` | Validate prediction callback receipt | 120s | 3 | nonce/receipt validation and idempotent state |
| `ai-results` | Persist canonical prediction result | 300s | 3 | receipt locking and transactional persistence |
| `ai-status-projections` | Project processing status | 60s | 5 | one-hour unique lock plus idempotent projection |
| `geo-publications` | Register Geo publication | 60s | 3 | one-hour unique lock plus outbox uniqueness |
| `geo-publication-preparation` | Prepare canonical Geo projection | 120s | 5 | one-hour unique lock plus reconciliation |
| `prediction` | Dispatch sequence prediction | 120s | 3 | database reservation and response ownership |
| `find-address` | Resolve sequence address | 60s | 3 | one-hour sequence unique lock |
| `ukm-scoring` | Calculate sequence UKM scores | 600s | 3 | one-hour sequence unique lock and idempotent state |

Feature flags determine whether these jobs are dispatched. Unfinished AI, enrichment, scoring, and publication flows remain disabled until their staging gates pass.

## Queue Timing Invariant

The active asynchronous queue connection must keep an in-flight job invisible longer than the longest job timeout. Application boot enforces:

```text
longest job timeout: 600 seconds
minimum retry/visibility window: 660 seconds
minimum process-manager graceful stop: 720 seconds
```

Database queue deployments set `DB_QUEUE_RETRY_AFTER=660` or higher. Redis and Beanstalkd use their equivalent variables. SQS deployments must set `SQS_VISIBILITY_TIMEOUT=660` or higher and separately verify the real provider queue policy; the local value is evidence for application startup, not proof that the remote queue was changed.

Do not reduce these values to make stuck jobs retry faster. Detect dead workers through process health and queue-age alerts. Increasing any job timeout requires updating ADR 0022, the central invariant, process-manager shutdown, provider visibility, tests, and this runbook.

## Worker Pools

Start with separate supervised pools so expensive spatial work cannot block callbacks or public workflow completion. These commands omit environment-specific process counts; set concurrency from measured queue arrival rate, service limits, database load, and p95 completion time.

```bash
php artisan queue:work --queue=ai-callbacks,ai-results --sleep=1 --timeout=600 --max-time=3600 --memory=512
php artisan queue:work --queue=ai-status-projections,geo-publications,geo-publication-preparation --sleep=1 --timeout=600 --max-time=3600 --memory=512
php artisan queue:work --queue=prediction,find-address --sleep=1 --timeout=600 --max-time=3600 --memory=512
php artisan queue:work --queue=ukm-scoring --sleep=1 --timeout=600 --max-time=3600 --memory=1024
```

Job classes declare their own timeout, tries, backoff, and unique behavior. The CLI values are fallback safety limits, not permission to override reviewed job policy. Process definitions must:

- run as a dedicated non-login application user
- start in the current immutable release directory
- inherit configuration only from the deployment secret/configuration boundary
- restart on failure with bounded rate limiting
- send stdout/stderr to the approved log pipeline
- provide at least 720 seconds for graceful stop
- replace workers after a bounded lifetime to release stale code/resources
- never run two release revisions against incompatible schemas or feature flags

After an atomic code/config switch, run:

```bash
php artisan queue:restart
```

Wait until old workers finish active jobs and replacement workers report the new release revision. A successful command only writes the restart signal; it does not prove that a process manager replaced every worker.

## Failed Jobs and Replay

Use read-only inspection first:

```bash
php artisan queue:failed
```

Before `queue:retry`, identify the job class, release revision, failure category, external side effects, receipt/sequence/publication state, and whether the current code can safely reconcile the attempt. Retry one reviewed job or bounded cohort, then verify its database and external outcome.

Do not use `queue:flush`, broad `queue:retry all`, direct queue-table deletion, or cache clearing as incident shortcuts. They destroy evidence or can replay non-idempotent side effects. Preserve failed payload access as sensitive operational data because serialized jobs can contain internal identifiers.

## Scheduler

The application currently defines no scheduled tasks; `php artisan schedule:list` reports that state. Therefore a scheduler process is not required for the implemented workload yet.

Before registering the first task:

- document ownership, cadence, timeout, locking, overlap policy, retry behavior, and failure alert
- use `withoutOverlapping` and `onOneServer` where distributed duplicate execution is unsafe
- prove the cache lock store is shared across scheduler nodes
- add a feature test or command test and update this runbook
- then configure exactly one scheduler trigger, normally `php artisan schedule:run` once per minute

Expired AI nonce cleanup, failed-job pruning, batch pruning, telemetry rollups, and legacy scheduled jobs remain explicit design decisions. Do not add destructive pruning merely to make tables look small; retention and incident evidence requirements must be approved first.

## Cache and Locks

The current production-oriented default is the database cache store. Unique queue jobs and scheduler overlap locks must use a store shared by every backend/worker node. A local file or array cache is not valid for distributed uniqueness.

Treat these cache classes separately:

- Laravel bootstrap cache: generated from reviewed code/config during deployment
- application data cache: may affect API freshness and load
- queue/scheduler lock cache: protects duplicate work
- image-server NVMe cache: outside Laravel and rebuilt from image storage
- GeoServer/tile/CDN caches: outside Laravel and invalidated through their own reviewed procedures

Use `php artisan config:cache` after injecting deployment configuration. Clear and rebuild bootstrap caches only inside the release procedure. Do not run `php artisan cache:clear` while unique jobs are active unless the release/incident owner has accepted duplicate-dispatch risk and a reconciliation plan.

## Logging and Rotation

Production should send logs to stderr/stdout for container or service-manager collection, or use Laravel's daily channel with an explicit `LOG_DAILY_DAYS` retention. Do not leave `LOG_LEVEL=debug` in production. Rotation, compression, access control, shipping, retention, deletion, and disk-pressure alerts are infrastructure responsibilities.

The optional API request event is disabled by default. When enabled after staging approval it contains only request ID, method, normalized route name/template, response status, and duration. Do not add request/response bodies, query values, concrete path parameters, authorization, cookies, IP addresses, user identity, or user-agent data.

Logs from PHP, reverse proxies, workers, database, AI, image server, anonymizer, GeoServer, and deployment tooling may still contain sensitive operational evidence. Restrict access, preserve UTC timestamps and request IDs, and follow the incident runbook before rotation or cleanup during an investigation.

## Deployment Artifact

Build one immutable artifact for the reviewed Git commit. The trusted build must include:

- Composer production dependencies installed from `composer.lock` with development dependencies excluded
- Vite assets built from `package-lock.json`
- application source and public assets
- no `.env`, credentials, local database, tests, dependency caches, logs, backup files, or writable runtime state
- recorded commit, dependency lock hashes, and artifact digest in restricted release evidence

CI must pass Quality and Secret Scan on the exact commit. Run `composer check-platform-reqs --no-dev` against the production PHP runtime or equivalent image before promotion.

## Deployment Sequence

1. Create the restricted release record and complete the release-readiness scope.
2. Verify immutable artifact provenance and both GitHub checks for the exact commit.
3. Inject secrets/configuration outside the release tree with least privilege and restricted permissions.
4. Boot the candidate in staging and run `php artisan config:cache`; unsafe queue timing must fail here.
5. Run `php artisan mapilio:verify-backup-readiness` against fresh infrastructure evidence before any migration.
6. Review `php artisan migrate:status`; run only the approved expand-compatible migrations with `php artisan migrate --force`.
7. Switch the web runtime atomically to the immutable release without changing client feature flags.
8. Reload/restart the PHP runtime according to its service manager, then run `php artisan queue:restart`.
9. Verify replacement worker revisions, queue age, failed jobs, `/api/v1/system/health`, API headers, auth, and representative compatibility reads through the real edge.
10. Enable only the approved canary feature flags in dependency order: backend compatibility, server-side workflow, then clients.
11. Observe the release checklist thresholds before expansion. Record GO, HOLD, LIMITED GO, or ROLLBACK.

Do not run migrations, queue replay, image smoke writes, GeoServer publication, or AI activation as an implicit Composer hook.

## Rollback Sequence

Prefer the smallest safe control:

1. stop expansion and disable newly enabled side-effect flags
2. pause affected worker pools when continuing work can create incompatible writes
3. preserve queue, callback, database, deployment, and external service evidence
4. atomically restore the previous known-good application artifact and configuration
5. reload the web runtime and signal workers with `php artisan queue:restart`
6. verify previous workers, health, auth, critical reads, queues, upload/anonymizer holdback, image serving, and GeoServer layers
7. reconcile writes accepted during the release window before replay, backfill, deletion, or traffic restoration

Do not automatically run `migrate:rollback`. A down migration can destroy data written by the new release or block old code for a different reason. Use the pre-approved forward fix, expand-contract plan, or infrastructure restore decision from the release record. Backup restoration requires incident/release owner authorization; a passing backup manifest is not authorization.

## Required Monitoring

Before production activation, dashboards and alerts must cover:

- API 5xx, 429, latency, and request volume by normalized route
- queue depth and oldest age per named queue
- worker count, restarts, timeout exits, memory exits, and release revision
- failed jobs by class without exposing serialized payloads
- PostgreSQL connections, locks, long transactions, replication/WAL state, disk, and slow queries
- AI dispatch/callback error and replay rejection rates
- upload, anonymizer holdback/failure, cache generation, and image serving errors
- GeoServer WFS/WMTS/vector-tile latency, errors, and cache state

Alert ownership and thresholds are deployment evidence, not source defaults. Start with staging measurements and revise after controlled production observation.
