# GeoServer Map Points Recovery

Date: 2026-08-12

This runbook covers the legacy `mapilio:map_points` vector-tile layer. It is
deliberately limited to GeoWebCache metatiling recovery. It does not authorize
database, layer schema, style, security, cache-retention, or memory-limit
changes.

## Confirmed Failure

A cache hit can return normally while an uncached point tile fails with
`400 Problem communicating with GeoServer`. The corresponding GeoServer log
contains:

```text
MVT metatile memory cap exceeded
```

The affected layer uses a `4 x 4` metatile. GeoServer applies the WMS request
memory cap to all 16 vector-tile builders in that metatile. A dense metatile can
therefore exceed the cap even when one 256-pixel tile is safe.

A read-only production investigation established all of the following:

- cached road tiles still return;
- uncached road and point requests must reach the GeoServer origin;
- the point layer's PostGIS bounding-box query uses a GiST expression index;
- one representative point tile completed through direct WMS/MVT rendering;
- the equivalent `4 x 4` point metatile crossed the configured memory guard;
- the failure is not justification for raising or disabling that guard.

## Approved Repair Shape

Change only the `mapilio:map_points` GeoWebCache metatiling factor from
`4 x 4` to `1 x 1` through the authenticated GeoServer administration surface.
Keep these settings unchanged:

- layer name and source SQL;
- MVT MIME type;
- grid set and extent;
- styles and parameter filters;
- client and server cache expiry;
- WMS request memory cap;
- JVM heap;
- existing cached tile files.

Do not edit catalog XML as the primary procedure. Do not restart GeoServer just
to apply the change when the supported administration surface can save and
reload the layer safely.

## Preconditions

1. Assign an operator and observer for the change window.
2. Record the current GeoServer version, layer id, `4 x 4` setting, JVM memory,
   WMS request memory cap, and service start time.
3. Take a restorable backup of the GeoServer data directory and separately copy
   the affected GWC layer configuration.
4. Confirm that the rollback owner can restore `4 x 4` without discovering
   credentials or paths during the incident.
5. Capture one known cache-hit tile and one safe cache-miss candidate. Never
   purge the full cache to manufacture a test.
6. Verify current application and database health before attributing every
   failure to GeoServer.

Stop if the backup, authenticated administration path, rollback owner, or
representative test coordinates are unavailable.

## Change Procedure

1. Open the authenticated GeoWebCache configuration for
   `mapilio:map_points`.
2. Change the metatiling factors from `4` by `4` to `1` by `1`.
3. Save the layer through the supported administration surface.
4. Confirm the saved configuration reports `1 x 1` before sending a cache-miss
   request.
5. Do not seed, truncate, or rename the layer during this change.

## Acceptance Tests

Run the checks from outside the GeoServer host and retain sanitized timings,
status codes, response sizes, and cache-result headers.

1. Request the known cached tile. It must remain `200`.
2. Request the approved cache-miss point tile. It must return `200` within the
   edge and client timeout budgets and contain a non-empty MVT response.
3. Repeat the same tile. The second response should be a cache hit and should
   not invoke another expensive origin render.
4. Request adjacent point tiles at the minimum client zoom and a normal road
   tile. Both layers must remain independently usable.
5. Confirm no new `MVT metatile memory cap exceeded`, out-of-memory, Jetty
   saturation, or database connection-pool errors appear.
6. Observe GeoServer heap, PostgreSQL active queries, system load, request
   latency, and error rate through at least one mobile retry interval.
7. Open the mobile map. The road overlay should remain visible, and the point
   overlay should recover automatically after its bounded health probe returns
   `200`.

The repair is not complete if only previously cached tiles work.

## Rollback

Rollback when cache misses still fail, latency materially regresses, database
load becomes unsafe, or an unrelated layer changes behavior.

1. Restore the metatiling factors to `4 x 4` through the same administration
   surface.
2. Save and confirm the restored value.
3. Do not raise the memory cap as part of rollback.
4. Preserve logs and sanitized request evidence for follow-up analysis.
5. Restore the backed-up layer configuration only if the administration
   rollback cannot restore the exact prior state.

## Follow-Up

After recovery, benchmark whether the legacy MVT attribute set is still needed
at every zoom. Web clients currently consume several image and capture
properties directly from point tiles, while the modern architecture provides a
versioned feature-detail API. Attribute reduction, geometry simplification,
zoom-dependent publication, and a versioned replacement layer require a
separate compatibility migration and must not be folded into this incident
repair.

## Production Application Evidence: 2026-08-12

The controlled repair was applied after the catalog-only archive and separate
layer backup passed checksum and archive-integrity checks. Only the
`mapilio:map_points` metatiling factors changed from `4 x 4` to `1 x 1`.
GeoServer then restarted cleanly and loaded the updated catalog.

Sanitized acceptance evidence:

- the representative point tile returned `200` as a real cache miss in 1.64
  seconds with a 1,444,791-byte MVT response;
- the repeated request returned `200` as a cache hit in 0.82 seconds;
- an adjacent point cache miss returned `200` in 1.31 seconds with a
  1,887,556-byte MVT response;
- the control road tile remained `200`;
- the mobile-equivalent bounded `HEAD` probe returned `200`;
- the iOS simulator reported both road and point overlays available and raised
  no MapLibre tile error;
- no MVT memory-cap, out-of-memory, GeoServer error, exception, or database-pool
  pattern appeared during the observation interval;
- the GeoServer database role had no active query after the checks.

Rollback was not invoked. Continue normal service monitoring and preserve the
backup until the change has passed the organization's retention window.
