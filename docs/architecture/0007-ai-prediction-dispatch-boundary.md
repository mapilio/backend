# ADR 0007: AI Prediction Dispatch Boundary

## Status

Accepted for local and staging integration. Disabled by default. Production activation is not approved yet.

## Context

The legacy upload worker sends sequence imagery metadata to the prediction service and records the returned response id in `default_mapilio_processing`. The old request has no authentication header, no explicit timeout policy, no idempotency reservation, and no protection against duplicate dispatches for the same sequence.

The legacy callback at `/webhook/response-prediction` is unsigned. It accepts an AI response id, status, and result payload, then writes a large detection graph across feature, measurement, bounding-box, relation, location, and segmentation tables. That callback remains closed in the modern backend until its trust and persistence contracts are rebuilt.

## Decision

The modern outbound boundary consists of:

- `DispatchSequencePrediction`, a queued job tagged with the sequence UUID
- `DispatchSequencePrediction`, a domain action that owns reservation, payload construction, HTTP dispatch, and status updates
- feature flags that keep both AI dispatch and automatic post-upload dispatch disabled by default
- an optional bearer token supplied only through environment configuration
- an `Idempotency-Key` header based on the sequence UUID
- HTTPS enforcement in production and normal TLS certificate verification
- explicit connection and request timeouts
- a row lock on sequence detail while checking or creating the local dispatch reservation
- automatic expiry of stale `dispatching` reservations before a queue retry
- reuse of active `dispatching`, `pending`, or `SUCCESS` processing records
- safe error messages that do not store the AI response body

The payload keeps the active legacy request contract: nested image `params`, `sequence_uuid`, project-specific or default `config_url`, and `callback: false`.

Automatic dispatch after upload occurs only when both `MAPILIO_AI_PREDICTION_ENABLED` and `MAPILIO_AI_DISPATCH_AFTER_UPLOAD` are true. Enabling one flag alone has no upload side effect.

## Security boundary

This ADR authorizes outbound request preparation only. It does not authorize production traffic, enable the legacy callback, or accept AI results.

Before callback activation, the AI server and backend must agree on:

- request signing or mutually authenticated service identity
- timestamp and nonce validation with replay rejection
- response-id ownership and sequence matching
- strict GeoJSON and detection payload schemas
- idempotent writes for features and all child records
- payload size, feature count, coordinate, and class-code limits
- durable callback receipt storage, retries, dead-letter handling, and audit events

## Staging gates

1. Configure a staging prediction endpoint, config URL, and service token.
2. Dispatch a non-production sequence and compare the request with the legacy service contract.
3. Confirm that the AI service honors the idempotency key or add a negotiated request id to the protocol.
4. Verify processing status transitions and queue retry behavior.
5. Implement and security-test the callback receipt boundary before accepting prediction results.
