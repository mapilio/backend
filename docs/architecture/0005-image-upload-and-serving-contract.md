# ADR 0005: Image Upload and Serving Contract

Date: 2026-07-14

## Decision

Keep the image server as a separate service during the backend migration.

The modern backend owns imagery metadata, sequence state, road-line generation, API compatibility, and downstream workflow orchestration. It does not store original image bytes in the Laravel application. Current clients still upload image bytes to the CDN/image server and send the returned hash to the backend metadata endpoint.

## Active Upload Contracts

Mobile app file upload:

- endpoint: `POST https://cdn.mapilio.com/api/upload/mobile`
- body: multipart form field `file`
- body fields: `email`, optional `project_organization_key`, optional `project_key`
- success shape: `files[0].hash`
- backend follow-up: `POST /api/function/mapilio/imagery/upload`

Mapilio-kit file upload:

- offset endpoint: `GET https://cdn.mapilio.com/upload/?fileName={session_key}&email={email}`
- offset success shape: `{ "totalChunkUploaded": number }`
- chunk endpoint: `POST https://cdn.mapilio.com/upload/`
- required headers: `content-range`, `X-File-Id`, `email`
- optional project headers: `project-organization-key`, `project-key`
- final success shape: `{ "hash": string }`
- backend follow-up: `POST /api/function/mapilio/imagery/upload`

Image serving:

- URL shape: `https://cdn.mapilio.com/im/{uploaded_hash}/{filename}/{size?}`
- common sizes: `100`, `480`, `1080`
- mobile environment key: `EXPO_PUBLIC_IMAGE_API=https://cdn.mapilio.com/im`

## Hash Meaning

`uploaded_hash` is not a content hash. In the current image server it is an encrypted directory-path token. The image server decodes this token, resolves the original image under the export path, creates or reads a cached JPEG under the cache path, and streams the image.

Because current clients and database rows depend on this value, the modern backend must preserve it as an opaque string. New code must not parse, validate, truncate, or regenerate it unless it is explicitly replacing the image-server contract.

## Modern Backend Responsibilities

- Accept upload metadata at `POST /api/function/mapilio/imagery/upload`.
- Provide a versioned alias at `POST /api/v1/imagery/uploads`.
- Store the returned image-server hash in `default_mapilio_imagery.uploaded_hash`.
- Store sequence summary rows in `default_mapilio_sequence_detail`.
- Generate point geometry and road-line records after metadata acceptance.
- Keep duplicate upload retries idempotent.
- Keep image URL generation compatible with the CDN URL shape above.

## Current Image Server Risks

The legacy image server remains production-critical and needs a separate hardening pass before a public release:

- The hash secret and decode behavior are legacy implementation details and must not be exposed in public docs.
- Upload validation is extension-blacklist based; MIME sniffing, JPEG validation, zip traversal protection, and size enforcement need to be hardened.
- Mobile upload uses body field names while some code reads similarly named headers, so compatibility tests must cover both.
- Mapilio-kit chunk upload uses server-side offset files and needs explicit stale partial cleanup.
- Uploaded originals, anonymized images, and generated cache files need a clear privacy race policy.
- Public image serving must prevent path traversal, cache poisoning, and access to unblurred originals.

## Cutover Rule

No production client should point upload traffic at the modern backend alone until a staging end-to-end test proves this chain:

1. mobile or mapilio-kit file upload returns an image-server hash
2. backend metadata upload stores imagery and sequence rows
3. point geometry and road lines are generated
4. anonymizer has completed or the image is held back from public serving
5. image URLs resolve from CDN cache/original storage
6. AI, score, and GeoServer jobs are either rebuilt or intentionally disabled with visible status

ADR 0019 provides the disabled-by-default, production-blocked smoke harness for the mobile and mapilio-kit image-server portions of this chain.
