# ADR 0038: Short-Lived Leaderboard And Country Aggregate Cache

- Status: Accepted
- Date: 2026-09-01

## Context

The unfiltered public leaderboard and country image-count endpoints run bounded
queries whose results change slowly but are requested frequently. Their legacy
and `v1` routes already share controllers and response contracts. Organization
leaderboards, filtered leaderboards, and marketplace payloads need more
payload-size, cardinality, and usage evidence before caching is appropriate.

## Decision

Use a small typed `PublicAggregateCache` helper backed by Laravel's configured
cache store and `Cache::flexible`. The default freshness window is 60 seconds;
the stale-through age is 300 seconds and the refresh lock is 10 seconds. Each
value is environment-configurable within bounded configuration values.

Cache only plain arrays for:

- leaderboard requests with none of `user_id`, `start_at`, or `finish_at`; and
- the bounded country reference projection returned by country image counts.

The leaderboard query normalizes its effective limit to
`max(1, min(config('mapilio.leaderboard.limit', 30), 100))`. Its literal,
versioned cache key includes that normalized limit, score version, and a SHA-256
fingerprint of the raw excluded and public role-policy values. This keeps keys
bounded, separates visibility or result-size changes, and never exposes role
values. Legacy and `v1` aliases with identical inputs intentionally share keys.

Responses, request objects, exceptions, validation failures, credentials, and
filtered results are never cached. Organization leaderboard and marketplace
controllers and query callers remain unchanged.

## Freshness And Failure Semantics

`Cache::flexible` serves a fresh value during the fresh window. During the
stale-through period it serves the stale value while one deferred,
lock-protected refresh runs. A refresh exception does not replace the stale
value; it remains available only until the existing stale-through expiry. The
system does not promise stale availability after that expiry, and cache
infrastructure remains a readiness dependency.

The helper does not add a second cache store, cache-outage fallback, or custom
cold-miss locking mechanism.

## Rollback

Set `MAPILIO_PUBLIC_AGGREGATE_CACHE_ENABLED=false`, rebuild Laravel's config
cache, and reload PHP-FPM and other long-running application workers. Editing
the environment alone does not change configuration already loaded by running
workers. Stored entries may remain because the disabled helper bypasses them.

## Consequences

Repeated eligible leaderboard and country-count requests avoid duplicate
computations during the short cache window while preserving exact wrappers,
ordering, route defaults, validation behavior, and legacy/`v1` parity. Issue
`#50` remains open for organization, marketplace, and filtered leaderboard
caching pending payload-size, cardinality, and usage evidence.
