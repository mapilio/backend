# ADR 0039: Bounded Public Read Results

- Status: Accepted
- Date: 2026-09-01

## Context

The public sequence-detail, embed, and uploaded-roads-group reads are legacy
compatibility surfaces. Their existing full-read behavior is useful to active
clients, but an unusually large result can consume too much database, PHP, and
response memory at once.

The production read-only evidence is diagnostic, not an SLO: the largest
active sequence observed 2,091 rows. Its sequence-detail response measured
718,277 bytes and 662.607ms; the corresponding embed response measured
1,001,456 bytes and 572.895ms. The largest road group measured 3,196 rows,
631,467 bytes, and 433.118ms. A historical legitimate upload contained
18,824 images. These observations size a guard; they do not establish a
latency or availability target.

Repository inspection found active clients using the legacy routes. No
inspected client uses the three versioned aliases below. The aliases remain
useful compatibility surfaces, but their default behavior must stay frozen for
callers that do use them without pagination.

## Decision

Enable the rollbackable full-read guard by default with these hard ceilings:

| Resource | Maximum rows | Configuration |
| --- | ---: | --- |
| Sequence-detail and embed imagery | 25,000 | `MAPILIO_PUBLIC_READ_MAX_IMAGERY_ROWS` |
| Uploaded road group | 10,000 | `MAPILIO_PUBLIC_READ_MAX_ROAD_ROWS` |
| Encoded item budget | 16 MiB | `MAPILIO_PUBLIC_READ_MAX_ITEM_BYTES` |

The feature gate is `MAPILIO_PUBLIC_READ_BOUNDS_ENABLED=true`. Under the
limits, legacy routes and parameter-free v1 aliases preserve their exact
existing status, wrapper, field, ordering, and empty-result behavior. The
guard measures JSON-encoded item bytes and returns this exact overflow
envelope when the row or encoded-item ceiling would be exceeded:

```json
{"success":false,"message":["Payload Too Large"],"error_code":413}
```

The three explicit v1 aliases gain additive pagination only when `page` or
`per_page` is present in the query. Both are canonical positive integer
strings; zero, signs, decimals, whitespace, duplicate/non-canonical forms,
and values outside the integer range are invalid. A missing member uses the
default: `page=1` and `per_page=500`. `per_page` may not exceed 1,000. Invalid
pagination returns this exact legacy-shaped envelope:

```json
{"success":false,"message":["'page' and 'per_page' must be positive integers within the supported range."],"error_code":422}
```

The paginated sequence-detail and road-group responses keep `data` as rows or
`null` and add `pagination` with exactly `current_page`, `per_page`, and
`has_more`. Embed keeps `info` and `entries` under `data` and adds the same
pagination object beside `data`. Pagination uses a `per_page + 1` sentinel,
never a total count, and deterministic ordering. Offset work is capped at the
endpoint's row ceiling; a page beyond that ceiling returns an empty/null data
page without querying an unbounded offset. Unknown embed sequences remain the
legacy `404` response:

```json
{"success":false,"message":["Not Found"],"error_code":404}
```

There are no v2 routes for this change. Additive behavior is already attached
to explicit v1 aliases, the default v1 behavior remains frozen, and creating
three undocumented v2 surfaces would add needless version churn.

The preserved route pairs are `/api/sequence-detail` with
`/api/v1/imagery/sequence-detail`, `/api/embed/{sequenceUuid}` with
`/api/v1/imagery/embed/{sequenceUuid}`, and `/api/get-uploaded-roads-group`
with `/api/v1/geo/uploaded-roads-group`. Existing clients use the legacy paths
and require full arrays, exact wrappers, and exact ordering. This extends the
existing v1 aliases instead of creating v2 routes because ADR 0003 reserves new
versioned paths for breaking changes; optional pagination is additive bounded
behavior, not a new resource model.

Marketplace remains outside issue `#87` and remains intentionally unchanged as
decided in [ADR 0038](0038-short-lived-public-aggregate-cache.md).

## Rollback

Set `MAPILIO_PUBLIC_READ_BOUNDS_ENABLED=false`, rebuild Laravel's config cache,
and reload PHP-FPM and other long-running application workers. An environment
edit alone does not change configuration already loaded by running workers.
This rollback disables only the full-read guard; explicit pagination remains
bounded and the route/envelope behavior otherwise remains unchanged.

## Consequences

The default read path now has a finite row and encoded-item budget while
existing callers under the ceilings retain exact behavior. Clients that need
large results can opt into deterministic pages on the v1 aliases without
requiring a new API version. The response intentionally omits total counts,
avoiding an additional count query and keeping the endpoint ceiling explicit.

Production measurements remain evidence for sizing and follow-up telemetry,
not an SLO or a promise that every deployment has the same database or network
latency.
