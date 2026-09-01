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
