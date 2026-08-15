# Hardening Rollout Sequence

## Purpose

The hardening work merged in August 2026 ships **disabled by default**. Deploying
the code changes nothing on its own; each control is activated separately, and
the order matters. This records that order and what must be true before each
step.

Read alongside `release-readiness.md`, which covers the release gates
themselves. This document only covers turning on what is already deployed.

## What is deployed but inactive

| Control | Flag | Default | Effect when enabled |
|---|---|---|---|
| Default API rate limit | `MAPILIO_API_RATE_LIMITING_ENABLED` | `false` | Counts requests and logs callers it *would* reject. Rejects nothing. |
| Rate limit enforcement | `MAPILIO_API_RATE_LIMITING_ENFORCE` | `false` | Returns 429 in the legacy envelope once the ceiling is exceeded. |
| Token revocation | `MAPILIO_MOBILE_AUTH_REVOCATION_ENABLED` | `false` | Denylist is consulted on every authenticated request. Revocations are **recorded regardless** of this flag. |
| Signing key rotation | `MAPILIO_MOBILE_AUTH_PREVIOUS_SIGNING_KEY` | empty | Verification also accepts the previous key, so a rotation does not log out live sessions. |

Two controls are active as soon as the code is deployed and need no flag:

- Imagery upload bounds (`MAPILIO_IMAGERY_UPLOAD_MAX_POINTS`, default 50,000;
  `MAPILIO_IMAGERY_UPLOAD_CHUNK_SIZE`, default 500)
- Image report bounds (`MAPILIO_IMAGERY_REPORT_MAX_MESSAGE_LENGTH`, default
  2000; `MAPILIO_IMAGERY_REPORT_RATE_LIMIT`, default 10/minute)

Both were sized well above observed real usage. The upload ceiling is roughly
2.7x the largest sequence seen in production (18,824 images).

## Step 0 — before enabling anything

- [ ] **Restricted:** confirm `MAPILIO_TRUSTED_PROXIES` contains the real edge
      IP/CIDR ranges. **This gates everything below.** An empty list behind a
      proxy makes every request appear to come from the proxy address, so all
      rate-limit buckets collapse into one — including the existing
      authentication throttle, which would then be shared across all users.
- [ ] **Restricted:** confirm `APP_DEBUG=false` and `APP_ENV=production`. Debug
      mode exposes stack traces and environment values, and disables HSTS,
      which is gated on the production environment.
- [ ] **Operator:** point the load balancer at `/api/v1/system/readiness`, not
      `/api/v1/system/health`. Readiness probes both databases and the cache and
      returns 503 when one is unreachable; health only reports that PHP is
      running. Keep health (or `/up`) as the liveness signal — they answer
      different questions and should stay separate.

## Step 1 — observe

- [ ] **Operator:** set `MAPILIO_API_RATE_LIMITING_ENABLED=true`, leaving
      `ENFORCE=false`.
- [ ] **Operator:** enable `MAPILIO_API_REQUEST_LOGGING_ENABLED` if it is not
      already on, and confirm log volume and retention are acceptable.
- [ ] **Restricted:** observe for at least one full traffic cycle including
      peak. The limiter writes `api.rate_limit` warnings naming the route and a
      hashed client fingerprint; no address is recorded.

Nothing is rejected during this step. That is the point: the log answers what
the ceiling should be, rather than the ceiling being guessed.

## Step 2 — size the ceiling

- [ ] **Restricted:** derive `MAPILIO_API_RATE_LIMIT_MAX_ATTEMPTS` from measured
      p99 client behaviour, not from a target. Start above the busiest
      legitimate caller.
- [ ] **Restricted:** identify server-to-server consumers behind a shared NAT
      address. They share one bucket under IP-based limiting and will be
      throttled as a group. Per-key buckets are not possible yet — routes carry
      no auth middleware, tokens are resolved inside controllers — so these
      consumers need their ceiling raised or their traffic separated at the edge.
- [ ] **Restricted:** confirm from the observe log that no legitimate caller
      reaches the chosen ceiling.

## Step 3 — enforce

- [ ] **Operator:** set `MAPILIO_API_RATE_LIMITING_ENFORCE=true`.
- [ ] **Restricted:** confirm a rejected request returns 429 with the legacy
      envelope `{"success":false,"message":["Too many requests."],"error_code":429}`
      plus `Retry-After`. Existing clients parse this shape; a different one
      breaks their error handling.
- [ ] **Operator:** watch 429 rate against the observe-period baseline. A rate
      materially above what the log predicted means the ceiling or the bucket
      key is wrong, not that abuse was found.

Rollback is a single flag: set `ENFORCE=false`. No deploy required.

## Step 4 — token revocation

- [ ] **Operator:** set `MAPILIO_MOBILE_AUTH_REVOCATION_ENABLED=true`.
- [ ] **Restricted:** confirm no latency regression. This adds one indexed
      lookup per authenticated request.

Until this is enabled the logout endpoint records the revocation but the token
still works. Revocations recorded while the flag is off take effect the moment
it is switched on, so the ordering is safe either way — but logout is not
actually a logout until this step is done.

Prune the denylist periodically; rows are only meaningful until the token's
natural expiry.

## Step 5 — signing key rotation (when needed)

1. Move the current key to `MAPILIO_MOBILE_AUTH_PREVIOUS_SIGNING_KEY`.
2. Set the new key as `MAPILIO_MOBILE_AUTH_SIGNING_KEY`.
3. Wait out the longest refresh token TTL (`MAPILIO_MOBILE_REFRESH_TOKEN_TTL`,
   default 10 hours).
4. Clear the previous key.

Tokens are always signed with the current key and verified against both, so no
session is lost and no client release is needed. Skipping step 3 invalidates
every token still signed with the old key.

## Mobile client ordering

- [ ] **Operator:** deploy this backend **before** releasing any mobile build
      that includes mapilio/mobile-apps#89. That build authenticates through
      `POST /api/v1/mobile/auth/public-token`; against an older backend the
      route does not exist and **every login fails**.

Merging the mobile change is safe on its own. The constraint is on shipping a
build.

## Still open

These are tracked as issues and are not part of this sequence:

- CORS policy (`#51`) — needs an inventory of real browser origins, which needs
  the request logging from step 1
- `statement_timeout` on runtime connections (`#52`) — needs the latency
  measurements from step 1
- Caching for the leaderboard and marketplace aggregations (`#50`)
- Backup and restore evidence, which `mapilio:verify-backup-readiness` gates but
  cannot itself produce
