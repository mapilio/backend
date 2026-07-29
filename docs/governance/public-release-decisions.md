# Public-Release Governance Decisions

The repository is public but remains a pre-release migration target. Public visibility, source quality, and secret scanning do not by themselves authorize a stable release or production cutover.

## Required Owner Decisions

### License

No license is currently selected and no `LICENSE` file should be added by implementation default. The owners must choose terms with appropriate legal advice, confirm compatibility with dependencies and intended community/data use, define copyright ownership, and approve how existing and future contributions are accepted.

Until then, default copyright applies and the repository must not claim that reuse, redistribution, or modification is licensed.

The root Composer package must remain `proprietary`, the private npm package must remain `UNLICENSED`, the OpenAPI document must say that the license is not yet selected, and no root `LICENSE` or `COPYING` file may be added while this gate is pending. `npm run check:license-state` enforces those statements without interpreting third-party dependency licenses. Once owners approve terms, replace this pending-state gate and all four surfaces in one reviewed change.

### Conduct reporting

The code of conduct defines behavior and enforcement principles, but accepting community contributions requires a confidential reporting channel, at least two assigned maintainers, privacy/retention rules, and a tested escalation path. Do not use public issues for conduct reports.

### Vulnerability reporting

GitHub private vulnerability reporting is enabled, and the public Security page renders `SECURITY.md` plus the reporting action. Complete the documented non-maintainer submission, primary/backup notification, private reply, and non-public closure exercise. Security reports must not be routed through public issue templates.

### Repository controls

- retain the four GitHub-Actions-bound Quality, PostGIS migration, and Secret Scan checks now required on protected `main`;
- decide whether to enforce protection for administrators, require pull-request review, and prevent every direct unreviewed change;
- assign maintainers for API, database, imagery/privacy, AI, GeoServer, mobile compatibility, and operations;
- approve and activate the drafted [issue triage labels, response expectations, and closure policy](../community/issue-triage.md);
- decide whether Discussions should be enabled and who moderates them;
- select still-relevant drafts from the sanitized [initial issue catalog](../community/initial-issue-catalog.md) and publish them with assigned reviewers.

### Public-content audit

The automated [public-content audit](../security/public-content-audit.md) now rejects private network/hostname patterns, personal email addresses, developer-local paths, risky artifacts, unapproved Mapilio hostnames, and prohibited legacy identifiers in the candidate tree and complete Git history without printing matched values. Reviewed historical exceptions are fingerprinted, path/commit-bound synthetic test fixtures that no longer exist in the current tree.

Before a stable release or production cutover, owners must still review provenance that pattern matching cannot prove: coordinates and identifiers claimed to be synthetic, approved public hostnames, commit/file names, author/committer identity metadata, operational descriptions, third-party notices, and every fingerprinted historical exception. The current history contains one inventoried non-reserved commit identity whose retention or coordinated rewrite is still an owner decision. This review covers the complete tree/history and records approval outside the repository. Passing Gitleaks and the automated public-content gate does not authorize a stable release or production cutover by itself.

## Launch Evidence

Record the stable-release and production-cutover go/no-go decisions outside the public repository with the exact revision, completed controls, assigned owners, unresolved risks, and rollback authority. Do not commit private contact details or completed sensitive evidence here.
