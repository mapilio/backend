# OpenStreetMap-Facing Backend Contract Map

## Status and scope

**Status:** Implemented draft; API/geospatial owner review required; non-publishable.

This is a repository-evidence-only map of backend surfaces that could support
OpenStreetMap (OSM) or community workflows. It is not an integration launch
notice, a service-level agreement, or permission to call production systems.

The repository describes support for OSM/community workflows, but does **not**
prove OpenStreetMap Foundation affiliation, endorsement, authorization, or an
active OSM integration. A mobile configuration key named `isOSMEnabled` is not
proof of any of those claims. See the [ecosystem boundary](../architecture/ecosystem.md).

Evidence used here includes:

- [repository README](../../README.md), [API routes](../../routes/api.php), and
  [API documentation index](../README.md);
- [ADR 0003: legacy compatibility endpoints](../architecture/0003-legacy-compatibility-endpoints.md);
- [ADR 0005: image upload and serving](../architecture/0005-image-upload-and-serving-contract.md);
- [ADR 0012: AI completion and Geo publication outbox](../architecture/0012-ai-completion-and-geo-publication-outbox.md);
- [ADR 0013: versioned AI geospatial projection](../architecture/0013-versioned-ai-geospatial-projection.md);
- [ADR 0014: versioned AI feature-detail API](../architecture/0014-versioned-ai-feature-detail-api.md);
- focused [AI feature tests](../../tests/Feature/AiFeatureDetailApiTest.php),
  [embed tests](../../tests/Feature/Legacy/EmbedImageCompatibilityTest.php),
  [sequence-detail tests](../../tests/Feature/Legacy/SequenceDetailCompatibilityTest.php),
  [uploaded-road tests](../../tests/Feature/Legacy/UploadedRoadsByGroupCompatibilityTest.php),
  and [imagery-upload tests](../../tests/Feature/Legacy/ImageryUploadCompatibilityTest.php);
- [SECURITY.md](../../SECURITY.md), [public-release decisions](../governance/public-release-decisions.md),
  and the [initial issue catalog](../community/initial-issue-catalog.md).

Claims below are limited to those sources. Anything labelled unknown requires
owner confirmation before a consumer, publication, or release decision.

## Decision-ready contract summary

| Surface | Direction | Repository evidence | Current boundary |
| --- | --- | --- | --- |
| `/api/v1/geo/ai-features/{featureId}` | Read | Explicit v1 route, controller, ADR 0014, feature tests | Public response shape in tests; 120/min throttle; no OSM-specific semantics proven |
| `/api/v1/imagery/embed/{sequenceUuid}` | Read | Versioned route and equality test with legacy embed path | Compatibility alias; no new OSM promise |
| `/api/v1/imagery/sequence-detail` | Read | Versioned route and equality test with legacy sequence path | Compatibility alias; query envelope is legacy-shaped |
| `/api/v1/geo/uploaded-roads-group` | Read | Versioned route and equality test with legacy road-group path | Compatibility alias; query envelope is legacy-shaped |
| `/api/v1/imagery/uploads` | Write | Versioned route, authenticated controller, upload tests | Mobile/Mapilio Kit imagery metadata write; not an OSM writeback contract |
| GeoServer / WFS / WMTS delivery | Read/publication outside Laravel | ADRs 0012/0013 and operations evidence | External, disabled, unverified, and owner-gated |

The three versioned legacy paths preserve existing behavior by design. They are
not a promise of a new resource model, OSM import/export, or future stability
beyond the compatibility/versioning decisions already recorded in the repository.

## Verified backend read surfaces

### AI feature detail

`GET /api/v1/geo/ai-features/{featureId}` is the clearest modern geospatial
read. The route constrains `featureId` to a number and applies `throttle:120,1`.
The controller and tests show:

- success is `{ "data": <GeoJSON Feature> }`;
- the feature has a WGS84 `Point` geometry, class/confidence/verification and
  approved attributes, ownership keys, timestamps, ordered matches, and image
  observations where active same-sequence imagery is available;
- callback receipt and prediction response identifiers are deliberately absent;
- an unknown id returns `404` with `{ "message": "Not Found" }`;
- invalid graphs, invalid geometry, database failure, or an excessive match
  graph return `503` with `{ "message": "AI feature detail is unavailable." }`;
- the response is cacheable with configurable public max-age and
  stale-while-revalidate, plus an ETag. The tests use 60 seconds and 300
  seconds as configured synthetic values.

The route has no explicit authentication middleware in `routes/api.php`, and
the focused tests call it without a bearer token. That is evidence of the
repository test boundary, not authorization for an unreviewed external client.
The feature is a Mapilio backend read; the repository does not establish that
it is an OSM API, an OSM data source, or an OSM editing endpoint.

### Versioned legacy-compatible GET aliases

The aliases below are route-level compatibility surfaces. ADR 0003 requires a
versioned alias for preserved legacy endpoints and says future breaking changes
must use a new versioned path. Each focused test compares the legacy response
JSON with the `/api/v1` response JSON.

#### `GET /api/v1/imagery/embed/{sequenceUuid}`

The success envelope is `{ "data": { "info": ..., "entries": [...] } }`.
Tests verify sequence information and ordered imagery entries containing fields
such as `photo_uuid`, `id`, `capture_time`, `filename`, `latitude`, `longitude`,
`uploaded_hash`, `sequence_uuid`, heading, resolution, and FOV values. Unknown
sequences preserve the legacy `404` envelope with `success: false`, a one-item
`message` array, and `error_code: 404`.

#### `GET /api/v1/imagery/sequence-detail`

The required query parameter is `sequence_uuid`. Success is `{ "data": [...] }`
or `{ "data": null }` for an empty result. Rows are legacy imagery metadata,
including identifiers, filename, opaque `uploaded_hash`, capture time, creator
key, resolution, heading/FOV fields, and sequence identity. Missing
`sequence_uuid` preserves the tested `400` error envelope.

#### `GET /api/v1/geo/uploaded-roads-group`

The required query parameter is `group_key`. Success is `{ "data": [...] }`
or `{ "data": null }`. Each tested row contains `sequence_uuid` and a
`linefeature` string containing a LineString JSON value. Missing `group_key`
preserves the tested `400` error envelope.

No explicit authentication middleware is attached to these GET routes in the
repository route file, and the compatibility tests call them without a bearer
token. Do not infer OSM access rights, rate limits, CORS behavior, retention,
or deprecation policy from that fact. Those are owner questions below.

## Synthetic shape examples

The following examples are **synthetic illustrations only**. They are not
captured traffic, fixtures, or promises of exact optional-field presence. They
use no credentials, real hosts, personal data, real imagery, or non-public
coordinates. All URLs use `example.invalid`; coordinates are deliberately
simple.

### AI feature read

```http
GET https://api.example.invalid/api/v1/geo/ai-features/7
```

```json
{
  "data": {
    "type": "Feature",
    "id": 7,
    "geometry": {"type": "Point", "coordinates": [0.0, 0.0]},
    "properties": {
      "class_code": "synthetic-class",
      "confidence": 0.5,
      "verified": false,
      "dimensions": {"width": 0.0, "height": 0.0, "area": 0.0},
      "attributes": {},
      "sequence_uuid": "synthetic-sequence",
      "project_key": null,
      "organization_key": null,
      "created_by_id": null,
      "created_at": null,
      "updated_at": null
    },
    "matches": []
  }
}
```

### Compatibility reads

```http
GET https://api.example.invalid/api/v1/imagery/embed/synthetic-sequence
GET https://api.example.invalid/api/v1/imagery/sequence-detail?sequence_uuid=synthetic-sequence
GET https://api.example.invalid/api/v1/geo/uploaded-roads-group?group_key=synthetic-group
```

```json
{
  "data": {
    "info": {"sequence_uuid": "synthetic-sequence"},
    "entries": [
      {
        "id": 1,
        "filename": "synthetic.jpg",
        "uploaded_hash": "opaque-example-token",
        "sequence_uuid": "synthetic-sequence"
      }
    ]
  }
}
```

The sequence-detail success wrapper is an array rather than the embed object,
and the uploaded-roads success wrapper is an array of `{sequence_uuid,
linefeature}` rows. Empty results use `data: null` for both tested legacy
compatibility queries.

### Authenticated imagery metadata write (not OSM writeback)

```http
POST https://api.example.invalid/api/v1/imagery/uploads
Authorization: Bearer <synthetic-token-not-for-use>
Content-Type: application/json
```

```json
{
  "options": {
    "parameters": {
      "json_data": [
        {
          "filename": "synthetic.jpg",
          "latitude": 0.0,
          "longitude": 0.0,
          "heading": 0.0,
          "altitude": 0.0,
          "orientation": 0,
          "captureTime": "2026-01-01 00:00:00",
          "deviceMake": "SyntheticMake",
          "deviceModel": "SyntheticModel",
          "imageSize": "4x3",
          "fov": 60.0,
          "sequenceUuid": "synthetic-sequence",
          "anomaly": 0,
          "roll": 0.0,
          "pitch": 0.0,
          "yaw": 0.0
        }
      ],
      "summary": {
        "Information": {
          "sequence_uuid": "synthetic-sequence",
          "hash": "opaque-example-token",
          "count": 1,
          "total_images": 1,
          "processed_images": 1,
          "failed_images": 0,
          "size": 0.01
        }
      }
    }
  }
}
```

The tests show a successful metadata response includes `status: true`, a
sequence identifier, and a count, and that a valid bearer token is required.
The actual image-byte upload is a separate image-server responsibility. This
write records Mapilio imagery metadata and may generate local quality/road
state; it is not an OSM-originated edit or OSM writeback contract.

## Image URLs and ownership

The backend owns imagery metadata, sequence state, URL construction, and the
opaque value stored as `uploaded_hash`. ADR 0005 says the image server owns
image-byte upload, original storage, cache generation, and delivery. The
backend does not store original image bytes or proxy them.

URL construction is based on a configured image-server base, image path prefix,
opaque hash, filename, and optional size variant. The AI feature tests verify
that URL path components are encoded and that original/preview variants can be
returned. Consumers must treat `uploaded_hash` as opaque: it is not a content
hash and must not be parsed, truncated, validated as a filename, or regenerated
by an OSM/community client.

An image URL or metadata row does **not** prove that imagery is anonymized,
privacy-released, licensed for redistribution, safe to publish, or retained for
any particular period. ADR 0005 and the ecosystem document leave anonymizer
holdback, original/cache races, takedown, and public-serving controls as
separate deployment gates.

## Writes and external geospatial delivery

### Backend write boundary

`POST /api/function/mapilio/imagery/upload` and its
`POST /api/v1/imagery/uploads` alias accept authenticated mobile/Mapilio Kit
metadata. Tests cover mobile and kit payloads, idempotent retries, stored
imagery/sequence rows, and validation/authentication failures. This is a
Mapilio capture-ingestion workflow. Nothing in the route, controller, or tests
proves that an OSM user initiated it, that it edits OSM objects, or that it
writes to an OSM database.

No OSM writeback, changeset, feature edit, import, or delete contract is proven
by this repository. Treat any proposed OSM write as a new, owner-approved
contract requiring identity, consent, attribution, licensing, conflict,
rollback, audit, and abuse controls.

### GeoServer and publication

GeoServer, WFS, WMTS, vector tiles, catalog configuration, styles, stores,
security, and caches are outside the Laravel backend's ownership. ADRs 0012
and 0013 state that publication registration/preparation/delivery are disabled
by default, delivery is not implemented or enabled, and a ready outbox entry
is not a published layer. The repository's operational evidence also records
that catalog reads require authentication and that no catalog mutation was
attempted.

Therefore GeoServer publication/delivery is external, disabled, unverified,
and requires API/geospatial/infrastructure owner approval plus staging
reconciliation, cache tests, permissions review, and rollback evidence. This
document does not authorize probing, catalog changes, layer activation, or
public OSM consumption.

## Read/write ownership and versioning

| Concern | Read owner | Write/decision owner | Versioning rule or evidence limit |
| --- | --- | --- | --- |
| AI feature detail DTO | Laravel API and canonical/legacy reads | Mapilio API/geospatial owners | `/api/v1` is explicit; ADR 0014 says coordinated client migration and rollback are required |
| Legacy imagery/road GET envelopes | Laravel compatibility controllers and legacy source | API owner during migration | Preserve existing envelope; breaking changes require a new version per ADR 0003 |
| Imagery metadata and sequence state | Laravel backend | Authenticated Mapilio mobile/kit workflow | v1 alias mirrors legacy write; not an OSM write version |
| Image bytes, originals, variants, and cache | Image server/storage boundary | Image-server/storage/privacy owners | URL shape is compatibility-sensitive; retention and cache invalidation are unknown |
| Anonymization and privacy release | Anonymizer/storage/public-serving boundary | Privacy/imagery owners | Metadata/URL cannot advance release state |
| Canonical spatial projection | PostgreSQL/PostGIS and Laravel preparation boundary | Geospatial owners | Future versioned view/layer is gated and not publication proof |
| GeoServer layers, WFS/WMTS/vector tiles | GeoServer | Infrastructure/geospatial owners | External catalog/auth/cache/rollback contract is unverified |
| OSM edits or writeback | No proven backend surface | OSM workflow and API owners | No version, permission, or writeback contract exists |

## Attribution, privacy, licensing, and governance

The project license is not selected. This document makes no conclusion about
imagery rights, OSM data licensing, third-party provider terms, database rights,
or whether any proposed attribution is sufficient. No consumer should infer a
right to copy, redistribute, modify, publish, or combine data from these routes.

Attribution text and placement are not specified by the verified route tests.
Privacy/anonymization readiness, retention, takedown, image publication
approval, and release of coordinates remain owner-controlled decisions. OSM
workflow owners must separately approve any attribution, privacy notice,
consent, data-rights assessment, and user-facing disclosure.

The [public-release decisions](../governance/public-release-decisions.md) state
that the repository is private, default copyright applies while the license is
pending, and public launch requires owner-reviewed provenance and controls. The
[security policy](../../SECURITY.md) prohibits sharing credentials, personal
data, unauthorized imagery, or testing third-party infrastructure without
permission.

## Explicit unknowns for owner resolution

Mapilio API/geospatial owners and OSM workflow owners must answer, with written
evidence before publication or integration:

1. Who are the actual consumers of each route today, including web, mobile,
   Mapilio Kit, community tools, and any OSM workflow?
2. Is any consumer an OSM editor, importer, changeset helper, or only a map
   viewer? Are there any intended OSM writes, and who authorizes them?
3. What exact attribution text, link, and placement are required for imagery,
   addresses, OSM data, AI-derived features, and GeoServer layers?
4. Who owns data rights and contributor consent for captured imagery, derived
   detections, coordinates, and any OSM contribution?
5. What privacy release proves anonymization, and who can approve, pause,
   quarantine, or take down originals, variants, tiles, and derived features?
6. What GeoServer catalog, workspace, store, layer, WFS/WMTS auth, style,
   GeoWebCache, CORS, cache invalidation, rollback, and audit contracts apply?
7. What URL retention, cache lifetime, CORS, signed/unsigned access, and
   deletion/takedown behavior applies to image URLs and opaque hashes?
8. What is the deprecation policy, compatibility window, telemetry, consumer
   identification, and incident contact for each v1 or legacy alias?
9. What owner-approved release evidence is required before enabling any AI,
   anonymizer, image-server, GeoServer, or OSM-facing flow?
10. Which attribution, licensing, privacy, conflict-resolution, retry, audit,
    and rollback controls would be mandatory for a future OSM writeback API?

## Out of scope and prohibited actions

This draft does not authorize or document:

- OpenStreetMap Foundation affiliation, endorsement, authorization, or active
  integration;
- OSM edits, imports, changesets, deletes, account linking, or writeback;
- GeoServer catalog inspection with credentials, layer mutation, publication,
  tile-cache activation, or external service probing;
- production requests, scraping, bulk download, credential use, or live upload;
- use of real coordinates, personal data, real imagery, or unredacted tokens in
  documentation, fixtures, issues, or examples;
- licensing, attribution, privacy, anonymization, retention, or takedown
  decisions on behalf of owners;
- treating a compatibility alias, URL, metadata row, outbox entry, or config
  flag as proof of public release or active integration.

## Human owner-review checklist

- [ ] API owner confirms every route, envelope, auth observation, cache behavior,
  and compatibility statement against the intended release revision.
- [ ] Geospatial owner confirms geometry semantics, source data, GeoServer
  boundary, catalog/auth/cache/rollback evidence, and disabled-state wording.
- [ ] Imagery/privacy owner confirms byte ownership, URL retention, anonymizer
  holdback, deletion/takedown, and public-serving controls.
- [ ] OSM workflow owner confirms whether any consumer or planned flow writes to
  OSM and supplies attribution, data-rights, consent, identity, conflict,
  audit, rate-limit, and rollback decisions.
- [ ] Governance/legal owner resolves project license, imagery/data rights,
  attribution sufficiency, privacy disclosures, and publication approval.
- [ ] Operations/security owner confirms no production probing occurred,
  credentials are absent, and external-service permissions are explicit.
- [ ] Maintainer records the exact reviewed revision, named approvers, unresolved
  unknowns, deprecation/telemetry plan, and publishability decision outside this
  non-publishable draft.

Until these checks are complete, keep this document marked **Implemented draft;
API/geospatial owner review required; non-publishable** and do not describe the
backend as an endorsed, authorized, active, or write-enabled OSM integration.
