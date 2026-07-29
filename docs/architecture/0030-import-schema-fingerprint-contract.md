# ADR 0030: Import schema fingerprint contract

- Status: Accepted for deterministic synthetic descriptors; real extraction and import remain deferred
- Date: 2026-07-29

## Decision

Define strict import schema descriptor v1 with exact keys, lowercase identifiers, bounded shape metadata, and PostgreSQL/SQLite engine labels. Read only an explicit regular non-symlink file with lstat/open/fstat device-and-inode checks and a 256 KiB bound; production fails before reading.

Canonicalization creates a new fixed-order object, sorts columns by contiguous position, encodes compact unescaped JSON, and hashes `mapilio-schema-fingerprint-v1\0` plus those bytes with lowercase SHA-256. The implementation is database-free, network-free, write-free, and deterministic. SQLite is synthetic/local-only, and cross-engine equality is not promised.

Identity import mapping schema v1 uses this algorithm for its source and target fingerprint fields without changing its JSON shape. A typed CLI fingerprint remains restricted owner evidence; the static validator cannot prove provenance by itself.

Twenty focused synthetic tests cover the fixed digest oracle, input-order independence, every included field, excluded metadata, JSON number semantics, object/array shape, identifiers, bounds, stable two-pass file reads, size, UTF-8, depth, symlinks, production refusal, and non-disclosing output. Independent security review approved the corrected implementation. No test opens a database or network connection.

## Exclusions and deferred work

This slice defers PostgreSQL/SQLite metadata extraction, owner-approved real descriptors and mappings, the importer, rows, reconciliation, and rollback/staging rehearsal. Examples are reserved synthetic values only, not actual Mapilio schema data, fingerprints, approvals, or database extraction.
