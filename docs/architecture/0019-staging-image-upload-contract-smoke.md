# ADR 0019: Staging image upload contract smoke harness

## Status

Accepted for local implementation. No live staging or production write was performed while adding the harness.

## Context

Mobile clients upload JPEG files through `POST /api/upload/mobile`, while mapilio-kit uploads a sequence ZIP through the resumable `/upload/` protocol. Both clients rely on the opaque returned hash and then send metadata to the backend. A backend-only metadata test cannot prove that image bytes, resume state, archive extraction, and generated CDN URLs still work together.

The legacy image server has no cleanup API. Its upload paths also remain security-sensitive and production-critical, so an automated check must not accidentally target production or follow redirects to an unexpected host.

## Decision

`php artisan mapilio:smoke-image-upload --confirm-write` runs a write-capable contract check against one explicitly configured non-production HTTPS origin. It is disabled by default, refuses Laravel's production environment, always rejects `cdn.mapilio.com`, requires an exact hostname allowlist, uses bounded connection/request timeouts, and does not follow redirects.

The harness generates a tiny JPEG, synthetic `example.invalid` identity, and unique artifact names. Mobile mode verifies multipart upload, `files[0].hash`, and the 480 image URL. Chunk mode builds a temporary ZIP, verifies a zero initial offset, uploads the first part, fetches and trusts the server resume offset, completes remaining parts, verifies the final offset and `hash`, and polls the extracted image's 480 URL. Hashes are treated as opaque and are not printed.

The command reports only cleanup identifiers. Operators must delete the synthetic staging artifacts through storage operations because the legacy service cannot do so itself.

## Configuration

```dotenv
MAPILIO_IMAGE_UPLOAD_SMOKE_ENABLED=true
MAPILIO_IMAGE_UPLOAD_SMOKE_BASE_URL=https://images.staging.example
MAPILIO_IMAGE_UPLOAD_SMOKE_ALLOWED_HOSTS=images.staging.example
```

Run one or both client contracts:

```bash
php artisan mapilio:smoke-image-upload --mode=mobile --confirm-write
php artisan mapilio:smoke-image-upload --mode=chunk --confirm-write
php artisan mapilio:smoke-image-upload --mode=all --confirm-write
```

## Remaining gates

1. Provision an isolated staging image-server origin and disposable NAS/cache paths.
2. Run the harness through the staging CDN/reverse proxy and remove reported artifacts.
3. Add a controlled oversized-upload test after staging ingress, application, storage quota, and cleanup limits are defined.
4. Verify anonymizer holdback: neither original nor generated cache may be publicly served before privacy processing succeeds.
5. Add an image-server cleanup API or scheduled stale-partial/smoke-artifact cleanup with strict authorization and audit logs.
6. Continue into the modern backend metadata endpoint with a staging database, then verify sequence rows, geometry, roads, AI flags, and GeoServer visibility.

## Consequences

The two active client upload contracts now have one repeatable, fail-closed staging check without moving byte storage into Laravel. The harness intentionally does not claim production readiness, oversized-upload protection, anonymizer safety, or end-to-end metadata completion until those separate staging gates pass.
