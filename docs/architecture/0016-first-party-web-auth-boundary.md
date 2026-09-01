# ADR 0016: First-party Web Authentication Boundary

## Status

Accepted for local and staging integration. The backend must be deployed before the matching web client. The final HttpOnly session/BFF design and direct social-provider migration remain open.

## Context

The legacy web application sent an OAuth client id and client secret from browser JavaScript for password, refresh, and social login. Any value shipped to a browser is public, so the client secret provided no confidential-client protection and was recoverable from tracked environment files and production bundles. Social access tokens were also included in query strings, increasing exposure through URLs and request logs.

The mobile compatibility endpoint still needs its existing client contract. Removing that contract globally would create an avoidable mobile regression.

## Decision

`POST /api/v1/web/auth/token` accepts bounded password and refresh-token grants from the first-party public web client without a client id or client secret. It uses the existing transitional bearer-token issuer so current protected API routes remain compatible, includes the user id expected by the web Redux flow, applies a ten-requests-per-minute IP throttle, and returns stable credential, inactive-account, validation, rate-limit, and unavailable responses.

The legacy `/api/v2/login` and versioned mobile token route continue requiring configured mobile client credentials. This explicitly separates public browser behavior from the mobile compatibility contract.

The web client now uses the versioned endpoint for login and refresh, correctly persists the rotated token pair, and rejects non-401 interceptor failures instead of swallowing them. Legacy social verification no longer receives a client id or secret. Its provider access token is sent in the POST body instead of the query string.

All four password-grant routes (`/api/v2/login`, `/api/v1/mobile/auth/token`, `/api/v1/mobile/auth/public-token`, and `/api/v1/web/auth/token`) perform one password-hash verification for both known and unknown accounts. Unknown accounts use a fixed, non-secret dummy hash configured for the active hashing driver and work factor. Startup configuration/cache boot fails safely when that hash is missing or stale; it must be deliberately regenerated when the hashing policy changes. This equalizes password-verification work, not exact end-to-end request timing. Responses, client contracts, refresh grants, and rate limits are unchanged. Rate limiting remains a separate layer; credentials and account identifiers are not logged, and no authentication-specific or comparative timing instrumentation was added.

The generic frontend `CLIENT_ID` and `CLIENT_SECRET` variables are removed from source configuration and tracked environment files. Provider application identifiers such as Google, Facebook, Apple, and OpenStreetMap client ids remain public by design and are not treated as confidential credentials.

## Release Gates

1. Deploy this backend route before the web client and configure a strong `MAPILIO_MOBILE_AUTH_SIGNING_KEY`; do not rely on an empty or exposed value.
2. Smoke-test password login, refresh after expiry, logout, disabled users, concurrent tabs, and protected API retries in staging.
3. Confirm trusted-proxy handling makes IP throttling effective behind the production reverse proxy and CDN.
4. Rotate/revoke the previously exposed confidential client credential and clean or protect repository history.
5. Migrate Google, Facebook, Apple, and OpenStreetMap verification plus social-account mapping into the modern identity domain before retiring the old social endpoint.
6. Replace JavaScript-readable bearer-token cookies with a secure, HttpOnly, SameSite session/BFF design; add CSRF controls, session revocation, device/session inventory, and security-event audit records.

## Consequences

No confidential OAuth client credential is required or bundled by the web application. Password and refresh behavior can move to the modern backend without changing mobile clients. This is an intentional compatibility step, not the final browser-session architecture: bearer and refresh tokens remain readable by JavaScript until the session/BFF migration is complete.
