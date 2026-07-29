# Secret Management and Scanning

## Policy

Secrets must never be committed to this repository, placed in frontend configuration, copied into issue text, or included in example output. Public OAuth application identifiers are configuration, but passwords, private keys, signing keys, bearer tokens, OAuth client secrets, provider API tokens, and database credentials are secrets.

`.env.example` contains names and non-sensitive defaults only. Runtime values belong in the deployment platform's encrypted secret store or a local ignored `.env` file with restricted filesystem permissions.

## CI Gate

`.github/workflows/secret-scan.yml` runs for every push and pull request, on manual dispatch, and weekly. It:

1. checks out complete Git history with credentials disabled
2. downloads the pinned Gitleaks release over TLS
3. verifies the official SHA-256 checksum before execution
4. scans every reachable Git revision and the checked-out tracked tree with findings fully redacted

The workflow does not use a baseline or allowlist. A detected credential must be investigated and remediated, not hidden to make CI pass. Repository settings should require the `Secret Scan / Gitleaks history` check before merging into protected branches.

## GitHub-Native Security Controls

The July 29, 2026 live-settings audit confirmed that Dependabot vulnerability alerts, Dependabot security updates, GitHub secret scanning, and repository push protection are enabled. Dependabot security updates are not paused. The initial fail-closed read-only review returned zero open Dependabot alerts, secret-scanning alerts, push-protection bypass alerts, and Dependabot pull requests.

Non-provider patterns and secret validity checks remain disabled, as do organization-wide defaults for new repositories and organization-wide two-factor authentication enforcement. Those broader or data-sharing controls require separate plan, privacy, account-readiness, and owner decisions.

Follow the [GitHub-native security controls runbook](github-native-security-controls.md) to verify live state, alert ownership, aggregate alert counts, and remaining organization decisions. Native controls supplement rather than replace the pinned Gitleaks workflow, local full-history scan, dependency audits, rotation policy, and public-content gate.

## Local Scan

Install Gitleaks `8.30.1`, then run:

```bash
scripts/security/scan-secrets.sh
```

The script scans tracked Git history plus tracked and commit-candidate files in the current worktree. Ignored runtime files such as `.env` and dependency directories are intentionally outside this repository gate. Scan those separately with redacted output when auditing a machine, and never upload reports containing secret material.

## Response to a Finding

1. Treat the credential as compromised, even when it was removed in a later commit.
2. Revoke or rotate it at the owning provider before changing history.
3. Identify affected systems, access logs, scopes, and time windows.
4. Remove the value from current files and replace the integration with server-side secret storage where required.
5. Decide whether coordinated history rewriting is appropriate for every clone, fork, tag, release, and deployment reference.
6. Re-run the full-history scanner and document the incident without reproducing the credential.

History rewriting is not a substitute for rotation. A secret may already exist in clones, caches, build logs, or third-party mirrors.

Gitleaks does not identify every private hostname, personal email, local path, tracked dump, media artifact, or non-secret operational identifier. The separate [public-content audit](public-content-audit.md) covers those repository-publication risks with redacted output; both gates are required.
