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

## Operational requirements

Production activation requires:

1. a write-capable least-privilege legacy identity database connection;
2. a real mail transport, monitored delivery, and a configured `APP_URL` that
   generates publicly reachable signed links;
3. callback allowlists covering only the deployed Mapilio web origins;
4. a persistent public or object-storage profile-photo disk with lifecycle,
   backup, and URL-serving behavior agreed with the image boundary;
5. Apple credentials and an owner-controlled readable `.p8` path for Apple
   deletion; and
6. isolated staging proof for registration, activation, known/unknown reset,
   profile image upload, provider-email confirmation, default deletion, Apple
   deletion, token invalidation, and legacy/versioned mobile calls.

Local SQLite tests and HTTP fakes prove application contracts only. They do not
prove SMTP delivery, public callback routing, object-storage durability, Apple
production credentials, PostgreSQL permissions, or store-build behavior.

## Deferred work

The old system's duplicate-email OpenStreetMap account-sync event is not
carried forward. A requested email already owned by another account is rejected
until account linking and merge ownership have a separate reviewed design.
Google and Facebook provider revocation are also deferred because current
clients submit the default deletion contract for those accounts. Direct social
provider verification remains governed by the mobile social-auth migration
boundary.
