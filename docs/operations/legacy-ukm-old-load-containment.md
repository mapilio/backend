# Legacy `ukm-old` Load-Containment Runbook

Date: 2026-08-12

This public-safe runbook covers the legacy `ukm-old` workload only. A
read-only production inspection found approximately 20.0 million imagery
rows, an 11 GB table, and 34 GB of indexes. `CalculateSequenceUKM` runs one
query per source image. The planner chose a parallel index-only scan of the
4.4 GB `idx_data` index with estimated total cost above 4.4 million because
the geography expression is not indexed. Two `ukm-old` Horizon workers had
no job timeout and had been occupied for more than two days; 238 jobs were
waiting. `pg_stat_statements` is installed but unavailable because it is not
in `shared_preload_libraries`. No production setting, process, or data was
changed during inspection. The production pause described below has **not**
been applied.

## Read-only preflight

Before any pause, the incident owner records the queue, active-worker, and
database-load evidence. Use a bounded session and do not run mutations:

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout = '5s';
SET LOCAL lock_timeout = '1s';
-- Run approved, bounded inspection queries only.
ROLLBACK;
```

Confirm the affected work is exclusively `ukm-old`, identify the supervisor
and its child workers through the scheduler's normal service view, and save
timestamps and query evidence. Do not expose host, account, credential,
process, or other private identifiers in incident notes.

## Controlled pause

Pause only the `ukm-old` supervisor using the normal deployment or scheduler
control. This prevents that supervisor from claiming new work; it does not
prevent other dispatch activity, so the `ukm-old` queue depth may rise while
uploads continue dispatching. Do not pause the general Horizon service,
upload workers, or other queues. Allow currently running work to finish where
practical.

Do **not** clear, delete, retry, or bulk-edit the queue. Before optional
termination, verify the worker is a confirmed `ukm-old` child, document its
side-effect boundary, and verify Redis retry and visibility behavior. Then
termination is permitted only with incident-owner approval and retained
pre-termination evidence. Interrupted jobs may remain reserved until the
queue's recovery or visibility behavior releases them. Never terminate an
unidentified or shared worker.

## Acceptance checks

After the pause, confirm database CPU and I/O are declining and confirm that
uploads, modern UKM work, and unrelated queues continue to dispatch and
complete. The legacy query pattern is expected to disappear only after
current jobs finish or a separately approved, confirmed-child termination is
completed; pausing the supervisor alone does not stop a multi-day in-flight
job. Record queue depth, active workers, errors, query activity, and a short
observation interval.

## Rollback or continue

If unaffected work is impaired, evidence is incomplete, or load does not
improve, keep the queue intact and escalate before further action. Resume only
the `ukm-old` supervisor after the incident owner approves and the cause is
understood. If checks pass, leave the supervisor paused and continue with the
permanent staging fix.

## Permanent staging fix

In staging, build and validate the existing geography expression index from
the [UKM PostGIS index plan](../database/ukm-postgis-index-plan.md), then
replace the per-image legacy job with the modern set-based job. Compare
runtime, rows read, scores, and no-neighbor behavior before a separately
approved production rollout. Do not change production settings or indexes as
part of this containment runbook.
