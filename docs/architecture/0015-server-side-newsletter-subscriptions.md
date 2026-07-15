# ADR 0015: Server-side Newsletter Subscriptions

## Status

Accepted for local integration. Production activation requires backend deployment and Mailcoach credential configuration before the web client is deployed.

## Context

The legacy web client called Mailcoach directly and embedded its base URL, list id, and bearer token in tracked frontend environment files and the browser bundle. A browser cannot keep a bearer token confidential. Removing the values without replacing the flow would break the public subscription form.

## Decision

`POST /api/v1/content/newsletter-subscriptions` is the only browser-facing newsletter boundary. It validates and normalizes the email address, accepts an optional `website` honeypot, applies a five-requests-per-minute IP throttle, and forwards valid requests to Mailcoach from the backend.

The provider base URL, API token, and UUID list id are server-only configuration. The action rejects malformed endpoints, credentials embedded in URLs, query strings, fragments, invalid list ids, and non-HTTPS provider URLs in production. Redirects are disabled and connection/request timeouts are bounded.

The operation returns the same `202` response for accepted, duplicate, and honeypot submissions. Mailcoach's duplicate `422` response is treated idempotently. Configuration, connection, and provider failures return a stable `503` message without exposing provider response bodies, credentials, or subscriber email addresses in application logs.

## Configuration

- `MAPILIO_MAILCOACH_BASE_URL`
- `MAPILIO_MAILCOACH_API_TOKEN`
- `MAPILIO_MAILCOACH_LIST_ID`
- `MAPILIO_MAILCOACH_SKIP_CONFIRMATION=true`
- `MAPILIO_MAILCOACH_CONNECT_TIMEOUT=3`
- `MAPILIO_MAILCOACH_REQUEST_TIMEOUT=8`

## Release Gates

1. Rotate the Mailcoach credential that was exposed to the browser and configure the replacement only on the backend.
2. Deploy and smoke-test the backend endpoint before deploying the web client change.
3. Verify CORS, trusted proxy IP handling, rate limiting, and provider timeout behavior through staging infrastructure.
4. Remove the provider variables from frontend configuration and confirm production bundles contain none of their names or values.
5. Review and clean repository history before the frontend repository is made public.

## Consequences

The web subscription form no longer requires a provider secret. The backend becomes responsible for provider availability and can change newsletter vendors without changing the public API. The web authentication client secret remains a separate critical finding and requires a dedicated first-party authentication/BFF design before removal.
