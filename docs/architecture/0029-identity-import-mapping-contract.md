# ADR 0029: Identity import mapping contract

- Status: Accepted for synthetic contract validation; owner-approved mapping and importer required
- Date: 2026-07-28

## Context

Identity is the dependency root for ownership across imagery, projects, AI work, and operator access. It must migrate before dependent records, but identity data also contains stable identifiers, contact data, and password hashes. A loose spreadsheet or ad hoc importer would make collision, nullability, password, and rollback decisions difficult to review or reproduce.

## Decision

- Define a strict, versioned JSON decision manifest for the `identity_users` domain before implementing any row import.
- Require exact source and target schema fingerprints plus one approval each from the data, identity, and security owner roles.
- Preserve at least one stable non-null external identifier, reject duplicate source or target mappings, reject unknown fields, and allow only the documented type and transformation vocabulary.
- Require exactly one credential mapping. Its transformation must match the declared preserve-supported-hash or force-reset strategy.
- Reject nullable source fields mapped to non-null targets because this contract has no implicit defaulting or lossy null conversion.
- Keep validation database-free, network-free, and write-free. Limit it to local, testing, and staging; production fails before the manifest is read.
- Read only one regular non-symlink file, cap it at 256 KiB, preserve JSON object/array distinctions, and expose only stable non-sensitive result labels.

The repository contains only synthetic examples. It contains no approved Mapilio field mapping, real approval record, user data, email address, password hash, or schema fingerprint.

Thirteen focused synthetic tests cover the accepted contract, production-before-read refusal, fingerprints, strict fields and shapes, owner approvals, file and depth bounds, UTF-8, external IDs, nullability, password policy, timestamp semantics, schema-version semantics, symlinks, and non-disclosing output. Independent security review approved the corrected implementation. The tests do not access a database or network.

## Consequences

The contract makes identity migration decisions reviewable without authorizing data access or writes. The next steps remain owner decisions and restricted evidence: select the durable legacy identity key, resolve duplicate emails, choose the password reset/hash policy, classify retained status/role/profile fields, generate real schema fingerprints, approve the restricted manifest, design an idempotent importer with rejected-row and reconciliation evidence, and rehearse rollback on isolated representative staging.
