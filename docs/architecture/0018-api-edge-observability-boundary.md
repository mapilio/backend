# ADR 0018: API edge security and observability boundary

## Status

Accepted for local implementation. Structured request logging remains disabled by default until staging establishes volume, retention, alerting, and privacy policy.

## Context

The modern API needs consistent browser hardening, request correlation, and operational telemetry without changing cache contracts or placing credentials and personal data into logs. Client-supplied correlation values can contain forged or unsafe data, while logging complete URLs, headers, bodies, cookies, IP addresses, or user agents would create a second sensitive-data store.

## Decision

Every `/api/*` and `/webhook/*` request receives a backend-generated UUIDv7. Incoming `X-Request-ID` values are ignored. The identifier is available in Laravel context during request handling and returned as `X-Request-ID` on normal and exception responses.

API and webhook responses receive these fixed headers:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`
- a permissions policy disabling camera, geolocation, and microphone
- a restrictive API-only content security policy

HSTS is returned only when Laravel is in production and the request is secure. Correct secure-request detection therefore depends on ADR 0017's explicit trusted-proxy configuration. Existing response bodies, ETags, CORS headers, and cache-control directives are not replaced.

When `MAPILIO_API_REQUEST_LOGGING_ENABLED=true`, one structured `api.request` event records only request id, method, normalized route template, named route, status, and integer duration in milliseconds. Actual route-parameter values are never recorded; an unknown path becomes `/api/(unmatched)` or `/webhook/(unmatched)`. Query parameters, body, IP address, user identity, cookies, authorization, headers, and user agent are excluded by construction. Server errors use error level; 429 and requests above `MAPILIO_API_SLOW_REQUEST_MS` use warning; remaining requests use info.

## Deployment gate

1. Verify headers on normal, validation, authentication, rate-limit, not-found, and server-error responses through the staging CDN and reverse proxy.
2. Confirm HSTS is absent on HTTP origin health checks and present only on the intended secure public hostname before considering preload.
3. Disable PHP `expose_php` and strip upstream implementation headers such as `X-Powered-By` or unnecessary `Server` detail at the production runtime/reverse proxy.
4. Enable request logging in staging and measure event volume, ingestion cost, p95/p99 latency, and cardinality.
5. Define access control, encryption, retention, deletion, and incident-use policy for operational logs.
6. Build alerts for sustained 429/5xx changes and latency thresholds after a normal baseline exists.
7. Keep edge/CDN request identifiers in a separate explicitly named field if cross-system correlation is later required; never replace the server-generated identifier with an untrusted header.

## Consequences

Clients and operators receive consistent correlation identifiers and API hardening without changing public JSON or cache behavior. The request logger is useful but intentionally not active by default, and it cannot replace metrics, tracing, edge logs, or a reviewed anomaly-detection policy.
