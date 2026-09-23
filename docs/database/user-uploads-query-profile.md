# User Uploads Query Profile

Sanitized evidence for the PostgreSQL user uploads query profile:

- An authorized representative contributor supplied the comparison evidence.
- The read-only PostgreSQL 14 comparison was a single warm-cache run with page size 10.
- The representative input produced 100,880 joined rows and 195 groups.
- Current page: 792.956 ms; separate count: 308.775 ms.
- Ordered candidate page: 578.966 ms; candidate EXPLAIN: 445.155 ms.
- A separate read-only smoke through the final `UserUploadsQuery` implementation returned 10 rows with the expected seven public fields and pagination total in 758.119 ms.
- All 10 returned groups, response fields, ordering, and the total matched at the contract level.
- Representative media selection intentionally aligns with portable latest-capture semantics; no byte-for-byte equality with previously unordered media values is claimed.
- An empty or out-of-range page uses two queries: the page query followed by the bounded total fallback.
- These measurements are evidence for this comparison only, not a latency SLO.
- No index, cache, or migration change was part of the candidate.
- No contributor ID or private value is recorded here.

## User upload details baseline, 23 September 2026

The detail routes (`/api/user-uploads-detail-v2` and
`/api/v1/imagery/user-upload-details`) are separate from the grouped feed query
above. They currently run a count followed by a page ordered by imagery ID.
Issue [#135](https://github.com/mapilio/backend/issues/135) tracks their cost and
a compatibility-safe bound; this baseline does not close that work.

A read-only PostgreSQL 14.24 inspection captured the current index definitions
and `EXPLAIN (ANALYZE, BUFFERS)` for two groups belonging to the same moderate-size
contributor. Each statement had a three-second timeout and 500 ms lock timeout.
Only aggregate counts and sanitized plans were retained, without contributor
IDs, group keys, media paths or coordinates. No data, indexes or server settings
were changed.

| Query | 40-row group, ms | 1,288-row group, ms |
| --- | ---: | ---: |
| Count | 34.681 | 36.287 |
| Page, limit 40 | 35.084 | 41.916 |
| Page, limit 250 | 32.923 | 39.543 |
| Page, limit 1,000 | 34.148 | 39.103 |
| Page, limit 3,000 | 35.141 | 36.512 |

These are single warm-cache database execution times, not HTTP latency or an
SLO. All page measurements use offset zero. The two groups returned at most
40 and 1,288 rows even when the requested limit was 3,000. Every plan reported
zero shared blocks read and zero temporary blocks read/written; the count/page
root reported 3,687-3,690 shared hits for the small group and 4,519-4,522 for
the larger group.

Catalog estimates were about 21.1 million imagery rows and 121,537 sequence
detail rows, not exact table counts. The sampled plans scan sequence detail
for the group and combine existing imagery indexes:

- `idx_imagery_seq_uuid`: B-tree on `(sequence_uuid)`.
- `idx_img_created_by_id`: B-tree on `(created_by_id, deleted_at, anomaly)`.
- No existing sequence-detail index starts with `group_key`.

The last point alone is not a reason to add an index: the sampled queries are
already inexpensive. A larger-group/staging comparison must demonstrate a
useful improvement before proposing one.

Focused repository tests now seed 3,001 synthetic imagery rows and enforce
exactly two queries for both aliases at the maintained caller limits of 40,
250, 1,000 and 3,000. First, last and out-of-range pages are checked, including
the SQL limit/offset, ordered IDs, totals and legacy empty response. This is a
query-count regression guard on SQLite, not PostgreSQL performance evidence.

Remaining work: representative groups exceeding 3,000 rows, large-contributor
and deep-page plans, cold-cache/I/O evidence in staging, and a caller-safe bound
or cursor transition with rollback notes. No cap, privacy policy, duplicate or
deleted-row behavior changes are part of this baseline.

### Larger-group follow-up

A subsequent bounded read-only inspection used the stored per-sequence counts
to select a larger group without scanning the imagery table for candidates.
Its declared count was 22,528; the actual non-anomalous joined result used by
this endpoint was 21,389 rows. These values differ because the metadata sum is
not the endpoint's exact filtered/joined count.

| Query | Execution time, ms | Returned rows |
| --- | ---: | ---: |
| Count | 46.121 | 1 aggregate |
| Page, limit 40 | 55.487 | 40 |
| Page, limit 250 | 59.744 | 250 |
| Page, limit 1,000 | 58.589 | 1,000 |
| Page, limit 3,000 | 62.864 | 3,000 |
| Page, limit 3,000, offset 15,000 | 70.076 | 3,000 |

This used the same three-second statement and 500 ms lock limits on PostgreSQL
14.24. Every root plan reported 7,087 shared hits, zero shared reads and zero
temporary reads/writes. The plans scan sequence detail, use
`idx_imagery_seq_uuid` for imagery, then sort/merge before applying the page.
The planner underestimated the joined rows; these single warm-cache timings
are not a cold-cache, concurrent-load or HTTP latency result.

No new index is proposed from these measurements, so an index migration and its
rollback are not applicable. No production configuration or data was changed.
Cold-cache/load work belongs in staging if later telemetry demonstrates a
problem; production caches must not be flushed to manufacture that benchmark.

The [response budget decision](../architecture/0039-bounded-public-read-results.md#upload-detail-pages-23-september-2026)
now covers this endpoint using the existing row/byte guard and rollback flag.
The updated regression budget is two queries for populated pages and one count
for empty/out-of-range pages. Groups larger than the response ceiling remain
accessible in ordinary pages. Existing client limits remain supported.

A repeatable-read, read-only application check compared the guard enabled and
disabled against the same 21,389-row group. JSON digests matched in all seven
cases: first-page limits 40, 250, 1,000 and 3,000; limit 3,000 on page 6; a
100,000-row request returning the full group; and an extreme out-of-range page.
The 3,000-row first-page response was 1,484,222 encoded bytes, and the complete
21,389-row response was 10,563,560 bytes, both below the default budget. These
sizes include pagination; the guard measures encoded items only. This compares
the two guard settings, not concurrent-load performance or production rollout.
