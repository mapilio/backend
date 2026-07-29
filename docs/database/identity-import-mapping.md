# Identity import mapping validation

Identity migration is dependency-first, but it is also PII-sensitive. The static validator checks a proposed v1 decision manifest without opening a database, importing data, writing files, or contacting a network. A passing result is not an approved Mapilio mapping or importer.

The `source.schema_fingerprint` and `target.schema_fingerprint` fields use the deterministic [import schema fingerprint algorithm](import-schema-fingerprint.md). This does not change the mapping JSON shape. A typed CLI fingerprint is still restricted owner evidence; this static validator can compare supplied values but cannot prove their provenance. The `sqlite` engine is synthetic/local-only, and fingerprints are not promised comparable across engines.

Run it only in local, testing, or staging with both actual schema fingerprints supplied:

```sh
php artisan mapilio:validate-import-mapping ./synthetic-identity-mapping.json \
  --source-fingerprint=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
  --target-fingerprint=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
```

The manifest must be strict JSON and must contain exactly the fields described by [the JSON Schema](identity-import-mapping.schema.json). Identifiers and example values below are reserved synthetic values only; they are not an approved production mapping.

```json
{
  "schema_version": 1,
  "manifest_id": "synthetic-identity-users-v1",
  "domain": "identity_users",
  "source": {"system": "example-legacy", "table": "legacy_users", "schema_fingerprint": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},
  "target": {"system": "example-mapilio", "table": "users", "schema_fingerprint": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"},
  "policy": {"collision": "reject", "unknown_columns": "reject", "pii_handling": "restricted", "external_ids": "preserve", "rollback": "required", "password_strategy": "preserve_supported_hash"},
  "approvals": [
    {"role": "data_owner", "approval_id": "synthetic-data-owner-1", "approved_at": "2026-01-01T00:00:00Z"},
    {"role": "identity_owner", "approval_id": "synthetic-identity-owner-1", "approved_at": "2026-01-01T00:00:00Z"},
    {"role": "security_owner", "approval_id": "synthetic-security-owner-1", "approved_at": "2026-01-01T00:00:00Z"}
  ],
  "mappings": [
    {"source_column": "legacy_id", "source_type": "bigint", "source_nullable": false, "target_column": "legacy_id", "target_type": "bigint", "target_nullable": false, "classification": "stable_identifier", "external_id": "preserve", "transformation": "identity"},
    {"source_column": "email", "source_type": "string", "source_nullable": false, "target_column": "email", "target_type": "string", "target_nullable": false, "classification": "contact", "external_id": "not_external", "transformation": "identity"},
    {"source_column": "password_digest", "source_type": "password_hash", "source_nullable": false, "target_column": "password_hash", "target_type": "password_hash", "target_nullable": false, "classification": "credential", "external_id": "not_external", "transformation": "password_hash_preserve"}
  ]
}
```

Stable failure output is `MAPPING_VALIDATION_FAILED` plus one reason code: `PRODUCTION_BLOCKED`, `MANIFEST_UNREADABLE`, `MANIFEST_TOO_LARGE`, `MANIFEST_INVALID_JSON`, `MANIFEST_SCHEMA_INVALID`, `OWNER_APPROVAL_MISSING`, `FINGERPRINT_REQUIRED`, `SCHEMA_FINGERPRINT_MISMATCH`, `MAPPING_DUPLICATE`, `EXTERNAL_ID_NOT_PRESERVED`, `PII_CLASSIFICATION_INVALID`, `NULLABILITY_UNSAFE`, `TRANSFORMATION_NOT_ALLOWED`, or `PASSWORD_POLICY_MISMATCH`.

Before any later gate, owners must answer: which legacy ID is retained; how duplicate email is handled; whether supported password hashes are preserved or all users reset; and which roles, status, and profile fields are retained. The next gates are restricted owner/public-content review, target/source schema evidence, importer design, rollback rehearsal, and security approval. The actual approved manifest may be versioned only after restricted owner/public-content review.
