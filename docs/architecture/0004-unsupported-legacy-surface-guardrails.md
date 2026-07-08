# ADR 0004: Unsupported Legacy Surface Guardrails

Date: 2026-07-08

## Decision

Do not expose high-risk legacy surfaces in the modern backend until their compatibility and security contracts are explicitly designed.

Unsupported API and webhook paths return a stable JSON 404 response instead of Laravel debug stack traces.

## Context

The legacy backend includes powerful generic and write-capable surfaces:

- dynamic `api/{class}/{function}` dispatch
- generic `api/entries/{namespace}/{stream}/{id}/{map?}` reads
- public label/feature write route
- unsigned AI prediction callback route
- legacy body-token dynamic APIs
- dynamic auth login/register routes

Those surfaces need route-by-route compatibility decisions, examples, authentication rules, and staging write fixtures before they can be safely rebuilt.

## Consequences

- Unknown dynamic dispatch requests are not automatically routed.
- Generic entry reads require an explicit allowlist before being exposed.
- Public write routes are not exposed until authentication, ownership, validation, and rollback behavior are designed.
- AI callbacks are not exposed until signature and idempotency rules are designed.
- Login/register routes are not exposed until rate limiting, token format, registration policy, and staging-user fixtures are confirmed.
- Legacy body-token dynamic APIs are not exposed until each allowed class/function pair has an explicit route and token behavior.
- API/webhook 404 responses do not leak stack traces, filesystem paths, or framework internals.

## Verification

`UnsupportedLegacySurfaceGuardrailTest` covers:

- unknown dynamic dispatcher path
- generic entry read path
- public label write route
- AI callback path
- dynamic auth login/register paths
- token-protected dynamic endpoint path

Each response must be HTTP 404 with the stable JSON body `{"message":"Not Found"}`.
