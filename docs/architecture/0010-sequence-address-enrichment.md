# ADR 0010: Sequence Address Enrichment

## Status

Accepted for local and staging integration. Disabled by default. No production provider is configured.

## Context

The legacy upload worker queues a reverse-geocoding job that checks imagery points until Photon returns an address. If it finds nothing, a second job repeats the scan with OpenCage. Provider exceptions are converted to empty results, the number of outbound requests is not bounded by configuration, and a found value overwrites every imagery address in the sequence.

Mapilio needs the sequence start address used by upload feeds without making upload acceptance depend on an external service or discarding addresses already supplied by clients.

## Decision

`ResolveSequenceAddress` provides a Photon-compatible reverse-geocoding boundary and `App\Jobs\ResolveSequenceAddress` runs it on a unique, retryable queue job.

Address precedence is deterministic:

1. keep an existing `sequence_detail.start_address`
2. otherwise use the first non-empty client-provided imagery `capture_address`
3. otherwise query a configured reverse-geocoding endpoint using eligible imagery in id order

Existing client addresses are never overwritten. A resolved value fills only blank, active, non-anomalous imagery addresses and sets the sequence start address.

The provider boundary:

- automatically dispatches after upload only when both enrichment and post-upload dispatch flags are enabled
- has no default endpoint, so production must choose a provider explicitly
- accepts only HTTP(S) URLs without embedded credentials and requires HTTPS in production
- does not follow redirects
- sends a configured user agent, explicit connect/request timeouts, and `limit=1`
- attempts at most the configured number of points, hard-capped at 10 per sequence
- accepts the Photon GeoJSON response shape and preserves the legacy street, district, city, state, then name fallback order
- never includes provider response bodies in stored errors

The legacy status values remain compatible: `1` found, `2` not found, and `3` error. Empty valid results become not found; transport errors, invalid payloads, and non-success responses remain retryable errors. Upload metadata is committed before the job is dispatched, so provider availability cannot roll back an upload.

## Provider capacity

The public [Photon](https://github.com/komoot/photon) demo permits reasonable use but does not guarantee availability and may throttle extensive traffic. Mapilio production must use a self-hosted Photon deployment or a provider with agreed capacity. Staging must measure request volume, hit rate, latency, and failure behavior before enabling automatic dispatch.

## Deferred work

- decide and provision the production reverse-geocoding provider
- verify OpenStreetMap-derived data attribution requirements wherever addresses are displayed
- add provider metrics, alerting, rate limiting, and a circuit breaker
- run staging samples across different countries and rural/urban imagery
- add an operator retry command for failed or not-found sequences
