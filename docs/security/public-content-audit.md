# Public-Content Audit

## Purpose

Secret scanners find credential patterns; they do not prove that a repository is appropriate to publish. Mapilio's additional gate scans the commit-candidate tree and every reachable Git revision for non-secret content that can expose people or private operations. It never prints the matched value.

Run the complete redacted gate:

```bash
npm run audit:public-content
```

When a trusted local maintainer needs remediation locations, run:

```bash
node scripts/security/audit-public-content.mjs --scope=all --show-locations
```

Location output contains only category, repository-relative path, line, and abbreviated commit. Do not paste private findings into issues, pull requests, CI annotations, or public reports.

## Automated Policy

The gate rejects:

- RFC 1918, link-local, carrier-grade NAT, unique-local IPv6, and other non-public address ranges while allowing loopback and RFC documentation ranges;
- hostnames ending in private-network suffixes and single-label service hosts embedded in common connection URLs;
- developer-specific macOS, Linux, Windows, and legacy web-root absolute paths;
- email-shaped values outside reserved example domains, except Git's public GitHub clone syntax;
- Mapilio hostnames not explicitly approved in `scripts/security/public-content-policy.json`;
- tracked environment variants, dumps, databases, logs, backups, private-key/certificate stores, archives, media, and office documents;
- prohibited legacy organization identifiers represented only by one-way hashes in policy.

The policy currently approves only three intentional public Mapilio contracts: the platform root, production API server declared by OpenAPI, and public image-delivery host used by URL contracts and the production-blocked smoke harness. Adding a hostname requires a reviewed policy change with a public reason.

Composer/npm lockfiles are still scanned for every category except third-party author email addresses. The exact lockfile-pinned Redoc runtime and its license notice are treated as vendored artifacts: their content is covered by dependency audit, integrity generation, and Gitleaks rather than this pattern gate. Their paths remain subject to risky-artifact policy.

## History Review

Seven commit/path/fingerprint-bound exceptions cover old synthetic proxy/parser fixtures introduced in two known commits. They contain no credential or production evidence and were replaced in the current tree with RFC documentation addresses and reserved example domains. Raw values are absent from policy. An exception that no longer matches reachable history fails as stale, and a new occurrence in another commit or path is not accepted.

History content is evaluated when it enters a commit: commit messages and added patch lines are scanned across every reachable revision. Removed lines are not attributed again to the later deletion commit. A file absent from the current tree remains covered by the commit that introduced its content.

The current candidate tree, reachable patches, and commit messages pass the automated gate. This means no unreviewed pattern in the defined categories was found; it does not prove data provenance.

Commit author/committer metadata remains a separate release decision because public contribution records contributor identities. The current history contains one distinct author/committer identity using a non-reserved email domain. Its value is not reproduced here. Owners must approve keeping that metadata or coordinate a history rewrite before a stable release.

## Required Owner Review

Before a stable release or production cutover, assigned owners must inspect the exact release revision and complete history for:

- provenance of coordinates, UUIDs, hashes, names, and records claimed to be synthetic;
- whether every approved public hostname and operational description is necessary;
- commit messages and file names that automation may not classify correctly;
- commit author and committer names/email addresses, including the currently inventoried non-reserved identity;
- imagery/media provenance and anonymization if reviewed assets are added later;
- third-party attribution and license notices;
- every fingerprinted history exception and any coordinated history-rewrite decision;
- related frontend/mobile repositories, which this backend gate does not scan.

Store the signed review, identities of reviewers, sensitive findings, and go/no-go decision in the restricted release system. Commit only a sanitized statement that the gate exists; do not commit completed private evidence.

## Limitations

The audit uses bounded deterministic patterns, not content classification or a production-data oracle. A plausible coordinate or identifier may be synthetic or real, and automation cannot decide which. Passing results do not replace credential rotation, Gitleaks, legal/license decisions, privacy review, verification of the enabled private vulnerability reporting flow, continued branch-protection enforcement, or the final public-content owner approval.
