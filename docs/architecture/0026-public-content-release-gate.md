# ADR 0026: Public-Content Release Gate

## Status

Accepted.

## Context

The repository is intended for future public contribution. Credential scanning alone cannot detect private hostnames, personal contact data, developer paths, internal network examples, tracked dumps/media, or non-secret legacy identifiers. Printing matched content in CI would create a second disclosure path.

## Decision

A dependency-free Node gate scans both tracked/commit-candidate files and every reachable Git patch plus commit message. Historical content is evaluated at introduction through commit messages and added patch lines, so removed values remain tied to their original revision instead of being reassigned to a deletion commit. It reports categories and counts by default; a trusted local option reveals only path, line, and abbreviated commit. Matched values are never printed.

The versioned policy explicitly approves public Mapilio hostnames, identifies third-party metadata treatment, records prohibited identifiers as SHA-256 hashes, and binds reviewed history exceptions to category, full commit, path, and value fingerprint. Exceptions require a public rationale and fail when stale. The candidate tree receives no historical exceptions.

Private-network test fixtures are replaced by RFC documentation networks and reserved example domains. The full local release script and the full-history Node CI job run focused scanner tests before enforcing the audit.

## Consequences

New private network examples, personal emails, local paths, risky artifacts, unapproved Mapilio hosts, or prohibited identifiers fail before merge without entering CI logs. Full-history checkout is required for the CI job. Exact vendored Redoc bytes and third-party author metadata receive narrowly documented treatment while Gitleaks and dependency/integrity checks still apply.

Pattern success is not proof that coordinates, identifiers, prose, commit identities, or historical exceptions are safe. Owner provenance review, repository-history decisions, related-client repository audits, license approval, and restricted go/no-go evidence remain mandatory before visibility changes.
