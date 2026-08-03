# ADR 0034: Protected main and required GitHub checks

- Status: Accepted; review portion superseded and extended by [ADR 0036](0036-reviewed-linear-main.md)
- Date: 2026-07-29

## Decision

Protect `main` with strict required status checks tied to the GitHub Actions application:

- PHP style, analysis, audit, and tests
- OpenAPI contract, npm audit, and asset build
- PostgreSQL 14 and PostGIS migrations
- Gitleaks history
- Dependency Review

A proposed head branch must be up to date with `main` before its required checks satisfy the merge gate. Force pushes and branch deletion are disabled.

This original policy did not enable administrator enforcement or mandatory pull-request reviews. Its review portion is superseded and extended by [ADR 0036](0036-reviewed-linear-main.md), which source-controls the accepted reviewed-linear policy without rewriting this historical decision. The ADR and CODEOWNERS file do not prove activation; live GitHub API verification is required. Administrator enforcement remains disabled pending a second administrator and tested signing/recovery evidence.

## Verification

GitHub's branch protection API reports `main` as protected, strict checking enabled, all five contexts bound to the GitHub Actions application, and force-push/deletion disabled.

## Limitations

Branch protection does not replace least-privilege maintainer access, non-maintainer private vulnerability reporting tests, release approval, or staging evidence. Administrators can still bypass this policy while administrator enforcement remains disabled, but any bypass is emergency-only and must follow the restricted record requirements in [ADR 0036](0036-reviewed-linear-main.md).
