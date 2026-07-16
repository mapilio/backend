# ADR 0022: Queue runtime safety boundary

## Status

Accepted for the modern backend. Worker concurrency and queue backend selection remain environment-owned decisions that require staging measurements.

## Context

The modern backend has jobs with timeouts from 60 to 600 seconds. Laravel's generated database, Redis, and Beanstalkd queue configuration previously used a 90-second `retry_after` default. A UKM job can therefore still be running when the queue makes the same payload available to another worker. Unique locks reduce some duplicate dispatches, but they do not replace a correct visibility window and not every job is unique.

Queue timing also affects shutdown. A process manager that kills a worker before its longest job completes can leave external requests or database work in an ambiguous state.

## Decision

The longest declared job timeout is 600 seconds. Async queue connections must prove a retry or visibility window of at least 660 seconds, leaving a 60-second margin.

`QueueRuntimeConfiguration` validates the active connection during application boot:

- database, Redis, Beanstalkd, and compatible drivers must provide a safe integer `retry_after`
- SQS must provide `SQS_VISIBILITY_TIMEOUT`; Laravel does not control the queue's remote visibility policy
- every child of a failover connection is validated, including cycle and missing-child detection
- sync, deferred, background, and null-style in-process drivers do not have a queue redelivery window and are skipped by this specific invariant
- missing, malformed, stringly typed, or too-small windows fail application boot without printing connection credentials

The default database, Redis, and Beanstalkd windows are 660 seconds. SQS has no assumed safe default and fails closed when selected without explicit visibility evidence.

A unit test discovers every `ShouldQueue` class under `app/Jobs`, reads its declared timeout, and requires the central maximum and retry margin to cover it. Increasing a job timeout without updating the invariant fails CI.

## Operational Rules

- Process-manager graceful shutdown must exceed the longest job timeout plus worker cleanup time; 720 seconds is the initial minimum.
- Deployments use `php artisan queue:restart` and wait for replacement workers instead of killing active processes.
- Latency-sensitive callback/result work, external requests, projections, and UKM scoring run in separate worker pools so a heavy spatial job cannot starve callbacks.
- Unique jobs require a shared cache/lock store across all workers. Clearing that store while jobs are active can permit duplicate work.
- Failed jobs are inspected and reconciled before retry. Bulk flush or blind replay is not a release operation.
- Queue backend changes require staging redelivery, crash, timeout, shutdown, and failover tests.

## Consequences

Unsafe queue timing becomes a startup error instead of a production duplicate-processing risk. The larger retry window delays automatic recovery of a genuinely dead 600-second job, so worker health detection and failed-job alerting must be faster and separate from queue redelivery. Correctness takes priority over aggressive duplicate execution.

This invariant does not prove external API idempotency, database transaction boundaries, SQS provider configuration, worker sizing, or operational monitoring. Those remain release gates in the runtime runbook and ecosystem release checklist.
