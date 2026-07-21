# ADR 0027: Pending License-State Gate

## Status

Accepted for the pre-public repository state.

## Context

Project documentation says that owners have not selected a license, but the root Composer metadata still inherited an MIT declaration from the original Laravel scaffold. That mismatch could incorrectly imply permission to use, modify, or redistribute Mapilio's own source. Dependency lockfiles and generated third-party notices legitimately contain their packages' license metadata and must not be rewritten as project licensing claims.

## Decision

Until owners approve project and contribution terms:

- the root Composer package declares `proprietary`;
- the private npm package declares `UNLICENSED`;
- OpenAPI declares `License not yet selected` and links to the governance decision;
- the repository root contains no `LICENSE` or `COPYING` file.

A dependency-free Node check validates these four surfaces. Focused tests prove that a permissive Composer claim, a publishable or licensed npm package, changed OpenAPI status/link, or root license file fails. The local release script and GitHub Quality workflow run the check. Third-party lockfile metadata and vendored license notices are deliberately outside its project-license classification.

## Consequences

The current metadata states that no open-source grant has been selected; it does not choose future terms or alter dependency licenses. A public launch remains blocked on owner and legal review. When terms are approved, maintainers must replace this pending-state gate, add the reviewed license and contribution terms, align Composer/npm/OpenAPI metadata, and update governance documentation in one change.
