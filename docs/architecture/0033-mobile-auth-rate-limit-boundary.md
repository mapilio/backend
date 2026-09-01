# ADR 0033: Shared mobile authentication rate-limit boundary

## Status

Accepted for local implementation. Staging must verify configured budgets, proxy behavior, and client compatibility before production rollout.

## Context

The legacy mobile login route and its `/api/v1` alias invoke the same authentication contract. They must not provide separate paths around abuse controls, and the limiter must not create a credential or account-data log/cache surface.

## Decision

Both mobile authentication POST routes use one named Laravel limiter, `mobile-auth`. It keys opaque fixed-prefix buckets by the resolved request IP and grant class (`password` or `refresh`); unknown and missing grant types use the stricter password budget. Password and refresh budgets preserve their raw environment/config values until limiter resolution. Strict integers and integer strings clamp to 1..1000; booleans, floats, fractional/empty/nonnumeric values use the grant-specific defaults of 10 and 30 requests per minute.

The limit response is a stable legacy-shaped JSON 429 with a generic message array. Laravel rate-limit headers, including `Retry-After`, remain present. No email, username, password, client credential, token, route, URL, or request body is used in the limiter key or emitted by the limiter response.

Password verification is a separate layer from this limiter. All four password-grant routes (`/api/v2/login`, `/api/v1/mobile/auth/token`, `/api/v1/mobile/auth/public-token`, and `/api/v1/web/auth/token`) perform one hash verification for known and unknown accounts; unknown accounts use the fixed, non-secret configured dummy hash. The active hashing driver and work factor must match that hash: configuration/cache boot fails safely on a missing or stale value, and a policy change requires deliberate regeneration. This does not promise exact timing equality, and it does not change responses, client contracts, refresh grants, or rate limits. Credentials and account identifiers are not logged, and no authentication-specific or comparative timing instrumentation was added.

## Consequences

Alias switching shares the same IP/grant bucket, while separate resolved client IPs and grant classes remain independent. Trusted-proxy configuration continues to determine the resolved IP; direct callers cannot spoof forwarded addresses. The limiter protects both compatibility surfaces without changing controller success or ordinary error contracts.

## Deployment gate

1. Set the two mobile-auth budgets explicitly in staging and verify the effective values after configuration caching.
2. Exercise both aliases through the real trusted proxy chain, including forwarded-IP spoof attempts against the origin.
3. Confirm stable 429 JSON, `Retry-After`, reset behavior, and compatibility responses on supported mobile clients.
4. Monitor 4xx/429 rates and latency during a bounded rollout; do not record credentials or request payloads in evidence.
