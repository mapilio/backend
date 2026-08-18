# Mobile Social Auth Migration Bridge

`POST /api/v1/mobile/auth/social-token` is a backend-first migration path for
mobile social login. It accepts a provider and provider-issued token, then
calls the configured legacy endpoint at
`{MAPILIO_LEGACY_SOCIAL_AUTH_BASE_URL}/oauth-api/{provider}/authenticate` with
`token`, `is_mobile: true`, and server-only configured legacy client
credentials. Client credentials supplied by a mobile request are ignored. It
never returns the legacy bearer response.

The bridge requires a non-empty legacy `access_token`, then uses it only
server-to-server as a bearer token against the same base URL's profile route.
It accepts a local id only from the exact profile envelope
`data: [{id: positive integer, ...}]`. That id is checked against an active,
non-deleted legacy user before the modern access and refresh pair is issued.
Provider SDKs are deliberately out of scope: this preserves current legacy
account mapping while the mobile clients migrate.

## Deployment

Deploy the backend route and tests first. Set
`MAPILIO_LEGACY_SOCIAL_AUTH_BASE_URL` to an HTTPS base URL in production, keep
`MAPILIO_LEGACY_SOCIAL_AUTH_CLIENT_ID` and
`MAPILIO_LEGACY_SOCIAL_AUTH_CLIENT_SECRET` in the server secret store, and
keep the bounded timeout and `10` requests/minute defaults unless traffic
evidence supports a change. Verify upstream 4xx/5xx behavior before enabling
the mobile client call path. Empty defaults intentionally fail closed with a
generic 503 until operations configure the bridge URL and credentials.

The later replacement is a provider-adapter layer owned by IdentityAccess.
That work can remove this legacy HTTP dependency after direct provider
verification and account-linking behavior have an approved migration plan.
