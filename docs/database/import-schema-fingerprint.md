# Import schema fingerprint

`mapilio:fingerprint-import-schema {descriptor}` computes a deterministic, database-free, network-free, write-free SHA-256 fingerprint from an explicit local JSON descriptor. It is allowed only in `local`, `testing`, or `staging`; production is refused before the descriptor is read.

The descriptor is strict v1 JSON: one regular non-symlink file, capped at 262144 bytes, UTF-8, a top-level object, maximum depth 32, and exactly the fields defined in [the JSON Schema](import-schema-fingerprint.schema.json). Runtime validation additionally enforces contiguous unique positions and unique names. `sqlite` is synthetic/local-only and its fingerprints are not cross-engine comparable.

Canonicalization creates a new object in fixed contract order, sorts columns by `position`, and encodes compact JSON with unescaped slashes and Unicode. The digest is lowercase SHA-256 over `mapilio-schema-fingerprint-v1\0` followed by the canonical JSON bytes. Paths, row counts, samples, values, defaults, indexes, keys, constraints, comments, owners, timestamps, and physical storage properties are excluded.

Success emits only `SCHEMA_DESCRIPTOR: PASS`, `CANONICALIZATION: PASS`, and `SCHEMA_FINGERPRINT: <digest>`. Failure emits `SCHEMA_FINGERPRINT_FAILED` and one safe reason code. Examples are reserved synthetic values only: no actual Mapilio schema, fingerprint, approval, or database extraction is included.
