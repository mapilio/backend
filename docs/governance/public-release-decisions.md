# Public-Release Governance Decisions

The repository is currently private. Source quality and secret scanning are necessary but do not by themselves authorize a public launch.

## Required Owner Decisions

### License

No license is currently selected and no `LICENSE` file should be added by implementation default. The owners must choose terms with appropriate legal advice, confirm compatibility with dependencies and intended community/data use, define copyright ownership, and approve how existing and future contributions are accepted.

Until then, default copyright applies and the repository must not claim that reuse, redistribution, or modification is licensed.

### Conduct reporting

The code of conduct defines behavior and enforcement principles, but public launch requires a confidential reporting channel, at least two assigned maintainers, privacy/retention rules, and a tested escalation path. Do not use public issues for conduct reports.

### Vulnerability reporting

Enable GitHub private vulnerability reporting, verify it from a non-maintainer account, and ensure the Security page renders `SECURITY.md`. Security reports must not be routed through public issue templates.

### Repository controls

- require Quality and Secret Scan checks on the protected default branch;
- require pull-request review and prevent direct unreviewed changes;
- assign maintainers for API, database, imagery/privacy, AI, GeoServer, mobile compatibility, and operations;
- approve and activate the drafted [issue triage labels, response expectations, and closure policy](../community/issue-triage.md);
- decide whether Discussions should be enabled and who moderates them;
- select still-relevant drafts from the sanitized [initial issue catalog](../community/initial-issue-catalog.md) and publish them with assigned reviewers.

### Public-content audit

The automated [public-content audit](../security/public-content-audit.md) now rejects private network/hostname patterns, personal email addresses, developer-local paths, risky artifacts, unapproved Mapilio hostnames, and prohibited legacy identifiers in the candidate tree and complete Git history without printing matched values. Reviewed historical exceptions are fingerprinted, path/commit-bound synthetic test fixtures that no longer exist in the current tree.

Before changing visibility, owners must still review provenance that pattern matching cannot prove: coordinates and identifiers claimed to be synthetic, approved public hostnames, commit/file names, author/committer identity metadata, operational descriptions, third-party notices, and every fingerprinted historical exception. The current history contains one inventoried non-reserved commit identity whose retention or coordinated rewrite is still an owner decision. This review covers the complete tree/history and records approval outside the repository. Passing Gitleaks and the automated public-content gate does not authorize public launch by itself.

## Launch Evidence

Record the final go/no-go decision outside the public repository with the exact revision, completed controls, assigned owners, unresolved risks, and rollback/visibility reversal authority. Do not commit private contact details or completed sensitive evidence here.
