# ADR 0014: Versioned AI Feature Detail API

## Status

Accepted for local and staging integration. The endpoint is available, but the current web client remains on legacy WFS until a coordinated staging migration is verified.

## Context

The web map currently reads a detection from the public `mapilio:features` WFS layer and parses its denormalized `matched_points` JSON. It then looks for the related imagery points in the currently rendered map tile. This couples object detail to a legacy table shape, unsafe raw filter construction, tile visibility, and a second client-side data join.

Canonical AI persistence already separates features, observations, and matches. The public API needs to expose the useful client contract without leaking callback receipts, AI response identifiers, processing state, or deleted imagery metadata.

## Decision

`GET /api/v1/geo/ai-features/{featureId}` returns one public GeoJSON feature with:

- class, confidence, verification, dimensions, approved attributes, ownership keys, and timestamps
- ordered matches with Point geometry and score
- two observations per match, including imagery id, bounding box, score, and optional segmentation
- active imagery metadata, Point geometry, and original/480 image URLs when the imagery belongs to the same sequence

The query uses four bounded reads regardless of match count: feature, matches, observations, and legacy imagery metadata. It does not perform a query per observation.

The API deliberately does not return callback receipt ids, prediction response ids, processing errors, or the legacy `matched_points` string. Missing, deleted, anomalous, or cross-sequence imagery remains identifiable by `imagery_id`, but its metadata is returned as `null`.

Canonical feature and match GeoJSON must be valid WGS84 Points. Invalid stored JSON, incomplete observation graphs, database failures, and graphs above the configured match limit return a stable `503` envelope without internal details. Unknown ids return the standard API `404` envelope.

Responses are public for compatibility with the existing public feature layer, rate limited to 120 requests per minute, and include configurable cache lifetime, stale-while-revalidate, and ETag support.

The contract is recorded in `docs/api/openapi-v1.json`.

## Configuration

- `MAPILIO_AI_FEATURE_API_CACHE_TTL=60`
- `MAPILIO_AI_FEATURE_API_STALE_WHILE_REVALIDATE=300`
- `MAPILIO_CDN_BASE_URL`
- `MAPILIO_CDN_IMAGE_PATH_PREFIX`
- `MAPILIO_AI_MAX_MATCHES_PER_FEATURE`

## Staging migration gates

1. Load representative canonical detections and compare the API response with the corresponding legacy WFS feature and imagery records.
2. Confirm the configured image base URL and prefix resolve original and 480-pixel variants through the image server.
3. Benchmark feature, match, observation, and imagery queries at representative graph sizes.
4. Verify CDN/API cache policy, ETag behavior, rate limits, CORS, and observability through the staging proxy.
5. Migrate the web object-detail fetch to the versioned endpoint behind a frontend feature flag.
6. Test object selection when related imagery is outside the currently rendered tile; the API response must remain sufficient.
7. Keep the legacy WFS detail path as a rollback until frontend production metrics are accepted.

## Consequences

Web object detail can move away from denormalized legacy WFS JSON without waiting for the GeoServer layer cutover. Existing production behavior is unchanged until the frontend flag is enabled. The response is intentionally a clean client DTO rather than a public representation of internal AI processing tables.
