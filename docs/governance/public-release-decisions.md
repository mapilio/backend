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

- retain the five GitHub-Actions-bound Quality, PostGIS migration, Secret Scan, and Dependency Review checks now required on protected `main`;
- retain the enabled repository-level Dependabot alerts/security updates, secret scanning, and push protection; assign primary/backup alert owners and review live state before release;
- retain and review the source-controlled Dependency Review workflow and weekly Composer/npm/GitHub Actions version maintenance; grouped version updates do not authorize merging, and security updates remain urgent and repository-setting-driven;
- retain native CodeQL default setup for Actions and JavaScript/TypeScript only. PHP is unsupported by CodeQL and remains covered by PHPStan, tests, Composer audit, and existing PHP quality gates;
- retain full-SHA policy, read-only default `GITHUB_TOKEN`, and disabled workflow approval;
- decide an organization-owner rollout for new-repository security defaults and two-factor authentication enforcement after account, automation, recovery, and access-impact review;
- retain the live `main` protections reconciled in [ADR 0036](../architecture/0036-reviewed-linear-main.md): the required pull-request review object remains present, while the temporary owner-approved solo-maintainer policy reports zero required approvals, no CODEOWNERS review, no stale-approval dismissal, and no last-push approval; resolved conversations, linear history, five strict up-to-date checks, and no force-push/deletion remain enabled;
- treat the temporary owner-approved solo-maintainer policy as an exception for unavailable independent review, not as equivalent to independent review and not as security, governance, or release readiness; the global CODEOWNERS rule names only `@ozcan-durak` during this period;
- after live API activation and verification, retain disabled merge commits, allowed squash/rebase and update-branch, automatic deletion of merged branches, and sanitized natural-language text when a squash body is needed;
- keep administrator enforcement disabled while there is only one administrator and no signing/recovery drill; decide second-administrator assignment, signed commits, and enforcement after restricted role and recovery evidence exists;
- keep required signed commits and administrator enforcement disabled until the human-controlled [administrator/signing readiness gate](../security/github-administrator-signing-readiness.md) is approved. Its live-state matcher does not declare readiness: the second global-CODEOWNER administrator, restricted evidence, non-production drill, and an approved alternative to maintainer-squashed Dependabot merges remain pending; enforcement is a separate owner decision even after signing compatibility passes;
- treat administrator bypass as emergency-only, with a restricted incident/recovery record, exact revision, reason, and follow-up review; never use it for routine or dependency work;
- assign maintainers for API, database, imagery/privacy, AI, GeoServer, mobile compatibility, and operations;
- approve and activate the drafted [issue triage labels, response expectations, and closure policy](../community/issue-triage.md);
- decide whether Discussions should be enabled and who moderates them;
- select still-relevant drafts from the sanitized [initial issue catalog](../community/initial-issue-catalog.md) and publish them with assigned reviewers.

### Public-content audit

The automated [public-content audit](../security/public-content-audit.md) now rejects private network/hostname patterns, personal email addresses, developer-local paths, risky artifacts, unapproved Mapilio hostnames, and prohibited legacy identifiers in the candidate tree and complete Git history without printing matched values. Reviewed historical exceptions are fingerprinted, path/commit-bound synthetic test fixtures that no longer exist in the current tree.

Before a stable release or production cutover, owners must still review provenance that pattern matching cannot prove: coordinates and identifiers claimed to be synthetic, approved public hostnames, commit/file names, author/committer identity metadata, operational descriptions, third-party notices, and every fingerprinted historical exception. The current history contains one inventoried non-reserved commit identity whose retention or coordinated rewrite is still an owner decision. This review covers the complete tree/history and records approval outside the repository. Passing Gitleaks and the automated public-content gate does not authorize a stable release or production cutover by itself.

## Launch Evidence

The public historical record records PR #16 as merged at `eaa49eb`, PR #17 as merged at `2770479`, and PR #18 as merged at `d63bdf0`. These merge records do not represent independent review or readiness approval.

Record the stable-release and production-cutover go/no-go decisions outside the public repository with the exact revision, completed controls, assigned owners, unresolved risks, and rollback authority. Do not commit private contact details or completed sensitive evidence here.
