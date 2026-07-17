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
- define issue triage labels, response expectations, and stale/closure policy;
- decide whether Discussions should be enabled and who moderates them;
- publish a sanitized initial issue set with clear scope and acceptance criteria.

### Public-content audit

Before changing visibility, review the full history and current tree for secrets, private hostnames/IPs, production identifiers, logs, dumps, backups, real imagery, personal data, and internal operational evidence. Passing Gitleaks does not detect every kind of private data.

## Launch Evidence

Record the final go/no-go decision outside the public repository with the exact revision, completed controls, assigned owners, unresolved risks, and rollback/visibility reversal authority. Do not commit private contact details or completed sensitive evidence here.
