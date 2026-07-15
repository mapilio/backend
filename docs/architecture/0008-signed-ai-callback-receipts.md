# ADR 0008: Signed AI Callback Receipts

## Status

Accepted for local and staging integration. Disabled by default. Detection-result persistence is separately disabled by default.

## Context

The legacy callback at `POST /webhook/response-prediction` accepts an AI response id, status, and result without authenticating the sender. It immediately queues a job that writes a large graph of features, measurements, bounding boxes, relations, locations, and segmentation records.

The modern backend must preserve the legacy URL and success response while preventing forged callbacks, replay attacks, oversized payloads, duplicate result processing, and unvalidated writes into geospatial tables.

## Decision

The modern backend provides two callback URLs behind the same disabled-by-default security middleware:

- legacy compatibility: `POST /webhook/response-prediction`
- versioned API: `POST /api/v1/ai/predictions/callback`

The AI service signs the exact HTTP request body with these headers:

- `X-Mapilio-Timestamp`: Unix timestamp
- `X-Mapilio-Nonce`: unique 16-128 character request nonce
- `X-Mapilio-Signature`: `sha256={hex_hmac}`

The signature input is:

```text
{timestamp}.{nonce}.{raw_request_body}
```

The signature algorithm is HMAC-SHA256 with `MAPILIO_AI_CALLBACK_SIGNING_SECRET`. The secret must contain at least 32 bytes. Requests outside the configured timestamp tolerance, requests with invalid signatures, and invalid nonce formats are rejected before database access.

Payload byte size, JSON nesting depth, accepted top-level statuses, and maximum feature count are bounded before a receipt is created.

Valid callbacks are stored in the modern database as:

- an immutable receipt fingerprinted by response id, response status, and payload hash
- an encrypted raw payload using the Laravel application key
- a separate unique nonce record with an expiry timestamp
- result feature count and processing state metadata

Reusing a nonce returns HTTP 409. Sending the same signed payload with a new nonce reuses the existing receipt and does not queue duplicate validation work.

New receipts are handed to `ValidatePredictionCallbackReceipt` on the configured callback queue. The job decrypts the payload, recomputes its hash, verifies response id and status consistency, and marks the receipt `validated`. When the separate result-persistence feature flag is enabled, validation queues the canonical persistence job. It does not write detections into legacy feature tables.

## Response compatibility

The legacy callback keeps the exact successful response:

```json
{"status":true}
```

The versioned callback returns HTTP 202 for a new receipt and HTTP 200 for an idempotent duplicate, including `receipt_id` and `duplicate` fields.

When `MAPILIO_AI_CALLBACK_ENABLED=false`, both routes return the existing stable JSON 404 response.

## Result persistence

Only validated receipts may enter result persistence. ADR 0009 defines the canonical feature, observation, and match model plus strict response ownership, class, imagery, coordinate, score, bbox, and payload-size validation. The complete graph is written idempotently in one transaction.

ADR 0012 adds a disabled-by-default durable processing-status projection and blocked Geo publication outbox. PostGIS geometry/index deployment, any proven-required legacy detection projection, actual GeoServer delivery, operator quarantine/retry controls, audit events, metrics, and expired nonce cleanup remain required before production activation.
