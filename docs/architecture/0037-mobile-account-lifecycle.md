# ADR 0037: Mobile account lifecycle

- Status: Accepted for local integration; staging email, storage, provider, and client evidence required
- Date: 2026-08-19
- Extends: [ADR 0004 - Unsupported legacy surface guardrails](0004-unsupported-legacy-surface-guardrails.md)

## Decision

The modern `IdentityAccess` domain owns the mobile account lifecycle without a
PyroCMS, Streams, or Passport runtime dependency. The following versioned
operations are implemented together with the exact legacy paths used by
already-published mobile builds:

- password account registration and signed email activation;
- non-enumerating forgot-password requests and one-time password renewal;
- authenticated profile and profile-photo updates;
- confirmation of a unique replacement for a provider-generated placeholder
  email; and
- account deletion that preserves contribution ownership while anonymizing and
  disabling the identity.

The old dynamic dispatcher remains closed. Only the named routes in
`routes/api.php` are exposed. New clients should use `/api/v1/mobile/...`;
legacy aliases exist only for compatibility and share the same services,
validation, authentication, and rate-limit buckets.

## Security boundaries

Registration and password-reset callbacks must use a configured scheme and an
exact allowlisted host. Activation, reset, and email-change links are signed,
expire, and carry no password. The password-reset request returns the same
success body for a known or unknown address. The code handed to the web reset
form is encrypted, short-lived, bound to one account, and consumed after one
successful password change.

Profile writes require a valid modern mobile bearer token. Profile images are
limited to 2 MiB and accepted only for reviewed image MIME types; storage uses
the configured persistent filesystem disk and stores a generated filename.
Email replacement is limited to provider-generated placeholder addresses and
does not change the stored email until the new address confirms the signed
link.

Deletion does not store the previous email, username, name, token, or profile
content in the closing reason. It replaces those fields, clears authentication
and recovery material, and sets both `enabled` and `activated` false. Because
mobile token resolution checks those flags on every use, all outstanding
access and refresh tokens become unusable without enumerating their token IDs.

For Apple accounts, the backend exchanges the one-time authorization code over
verified TLS and calls Apple's revocation endpoint before changing the user
row. Missing configuration, exchange failure, or revocation failure leaves the
account unchanged and returns a generic unavailable response. The private key
path and Apple identifiers belong in the deployment secret store, never the
repository.

Google and Facebook clients must explicitly send `login_type: google` or
`login_type: facebook`. The server resolves exactly one non-application link
from `default_social_authentications` for the authenticated Mapilio user and
the configured provider key. Missing, malformed or ambiguous links fail closed;
caller-supplied user IDs cannot select a different account. No legacy login
endpoint is called during deletion, so this flow cannot register a new account
or silently relink one by email.

- Google: obtain a fresh access token through the existing mobile AuthSession
  flow and send it as `provider_token`. The backend checks the Google UserInfo
  `sub` against the stored link before posting the token to Google's revoke
  endpoint. A different account or expired token is rejected before revocation.
- Facebook: the backend calls `DELETE /{stored-user-id}/permissions` with its
  server-only app access token. This also avoids sending an iOS Limited Login
  authentication token to Graph API. SDK logout alone is not revocation.

Both paths use fixed HTTPS hosts, reject redirects, and have bounded timeouts.
Tokens and upstream bodies are neither returned nor logged. After revocation,
the exact matched social link (including its old encrypted credentials) is
removed in the account-anonymization transaction. Other accounts and app-level
credential rows are untouched. The link is checked again before removal so a
concurrent relink cannot silently delete the replacement.

Remote revocation and the database transaction cannot be atomic. If the provider
times out or the local transaction fails after revocation, keep the account and
ask the user to sign in again before retrying. Never report successful deletion
on an uncertain provider result. This handles the selected login provider only;
multi-provider account linking is not introduced here.

## Operational requirements

Production activation requires:

1. a write-capable least-privilege legacy identity database connection;
2. a real mail transport, monitored delivery, and a configured `APP_URL` that
   generates publicly reachable signed links;
3. callback allowlists covering only the deployed Mapilio web origins;
4. a persistent public or object-storage profile-photo disk with lifecycle,
   backup, and URL-serving behavior agreed with the image boundary;
5. Apple credentials and an owner-controlled readable `.p8` path for Apple
   deletion;
6. exact `MAPILIO_GOOGLE_LEGACY_PROVIDER` and
   `MAPILIO_FACEBOOK_LEGACY_PROVIDER` values from the deployed social-link table,
   plus `MAPILIO_FACEBOOK_APP_ACCESS_TOKEN` and the deployed app's supported
   `MAPILIO_FACEBOOK_GRAPH_VERSION` for Facebook. These have no usable defaults.
   The Facebook app token must belong to the app that created those user IDs
   and must never be shipped to the mobile client; and
7. isolated staging proof for registration, activation, known/unknown reset,
   profile image upload, provider-email confirmation, default deletion, Apple
   deletion, Google deletion, Facebook deletion on both platforms, cancellation,
   wrong-account reauthentication, provider outages, token invalidation, and
   legacy/versioned mobile calls. Use disposable accounts, not contributors.

Local SQLite tests and HTTP fakes prove application contracts only. They do not
prove SMTP delivery, public callback routing, object-storage durability, Apple
production credentials, PostgreSQL permissions, or store-build behavior.

References: [Google token revocation](https://developers.google.com/identity/protocols/oauth2/native-app#tokenrevoke),
[Google UserInfo](https://developers.google.com/identity/openid-connect/openid-connect#obtaininguserprofileinformation),
and [Meta permission revocation](https://developers.facebook.com/documentation/facebook-login/guides/permissions/request-revoke).

## Deferred work

The old system's duplicate-email OpenStreetMap account-sync event is not
carried forward. A requested email already owned by another account is rejected
until account linking and merge ownership have a separate reviewed design.
Mobile adoption and real-provider verification of Google/Facebook deletion
remain tracked in [mobile #164](https://github.com/mapilio/mobile-apps/issues/164).
Current clients still submit the default contract, which deliberately retains
its old behavior; adding the server paths alone does not close that issue.
Direct social login verification remains governed by the mobile social-auth
migration boundary.
