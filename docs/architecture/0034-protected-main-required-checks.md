# ADR 0034: Protected main and required GitHub checks

- Status: Accepted and active
- Date: 2026-07-29

## Decision

Protect `main` with strict required status checks tied to the GitHub Actions application:

- PHP style, analysis, audit, and tests
- OpenAPI contract, npm audit, and asset build
- PostgreSQL 14 and PostGIS migrations
- Gitleaks history

A proposed head branch must be up to date with `main` before its required checks satisfy the merge gate. Force pushes and branch deletion are disabled.

Administrator enforcement and mandatory pull-request reviews are not enabled in this first policy. This preserves the current maintainer recovery and release path while preventing ordinary changes from bypassing the required checks. Those controls require a separate owner decision with a tested emergency path and reviewer coverage.

## Verification

GitHub's branch protection API reports `main` as protected, strict checking enabled, all four contexts bound to the GitHub Actions application, and force-push/deletion disabled.

## Limitations

Branch protection does not replace code review, least-privilege maintainer access, non-maintainer private vulnerability reporting tests, release approval, or staging evidence. Administrators can still bypass this policy until owner-approved enforcement is enabled.
