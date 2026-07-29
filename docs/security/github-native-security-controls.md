# GitHub-Native Security Controls

## Purpose

This runbook records the repository-level GitHub security controls that supplement Mapilio's required local and CI gates. GitHub settings are external state and can drift independently of the default branch, so owners must verify them against the exact repository before a stable release or production cutover.

Do not test secret scanning or push protection by committing a real, revoked, synthetic, or example-shaped credential. A test value may be mistaken for a live secret, reach a provider, create a public-history incident, or train contributors to bypass protection.

## Verified Repository State

The live `mapilio/backend` settings were verified on July 29, 2026:

- the repository is public and its default branch is `main`
- Dependabot vulnerability alerts are enabled
- Dependabot security updates are enabled and not paused
- GitHub secret scanning is enabled
- repository push protection is enabled
- the initial fail-closed API review returned zero open Dependabot alerts, secret-scanning alerts, push-protection bypass alerts, and Dependabot pull requests
- the required pinned Gitleaks full-history workflow, Composer audit, npm audit, and public-content audit remain independent controls

The zero-alert result is point-in-time evidence, not a permanent assurance. It does not prove that every secret format, private hostname, personal identifier, vulnerable runtime service, or future dependency is covered.

## Deliberately Separate Decisions

GitHub reports non-provider secret patterns and secret validity checks as disabled. The current organization plan does not meet GitHub's documented availability requirements for non-provider patterns. Validity checks can send a detected credential and limited context to its issuing service, so enabling them also requires an explicit owner privacy and incident-response decision.

The organization-wide defaults for Dependabot alerts, Dependabot security updates, secret scanning, and push protection are disabled for newly created repositories. Organization-wide two-factor authentication enforcement is also disabled. These broader settings are not changed by this repository runbook:

- security defaults affect other repositories and require an organization-owner rollout decision
- enforcing two-factor authentication can remove or block members who are not prepared
- owners must inventory accounts, recovery access, bots, deploy keys, GitHub Apps, and emergency administration before organization-wide enforcement

Record these decisions in the restricted governance system without publishing member names, account inventory, notification addresses, or recovery details.

## Read-Only Verification

Use Bash and an authenticated GitHub CLI session with repository administration and security-event read access. The strict shell options make an authentication, authorization, network, API, or pipeline failure stop the verification instead of resembling an empty alert list. These commands print settings and aggregate counts only:

```bash
set -euo pipefail

repository_controls=$(
  gh api repos/mapilio/backend --jq '
    .visibility == "public"
    and .default_branch == "main"
    and .security_and_analysis.dependabot_security_updates.status == "enabled"
    and .security_and_analysis.secret_scanning.status == "enabled"
    and .security_and_analysis.secret_scanning_push_protection.status == "enabled"
  '
)
test "${repository_controls}" = true

security_updates=$(
  gh api repos/mapilio/backend/automated-security-fixes \
    --jq '.enabled == true and .paused == false'
)
test "${security_updates}" = true

dependabot_alerts=$(
  gh api --paginate \
    'repos/mapilio/backend/dependabot/alerts?state=open&per_page=100' \
    --jq 'length' |
    awk '{ total += $1 } END { print total + 0 }'
)

secret_alerts=$(
  gh api --paginate \
    'repos/mapilio/backend/secret-scanning/alerts?state=open&per_page=100&hide_secret=true' \
    --jq 'length' |
    awk '{ total += $1 } END { print total + 0 }'
)

open_bypass_alerts=$(
  gh api --paginate \
    'repos/mapilio/backend/secret-scanning/alerts?state=open&per_page=100&hide_secret=true' \
    --jq '[.[] | select(.push_protection_bypassed == true)] | length' |
    awk '{ total += $1 } END { print total + 0 }'
)

dependabot_pull_requests=$(
  gh api --method GET search/issues \
    -f q='repo:mapilio/backend is:pr is:open author:app/dependabot' \
    --jq '.total_count'
)

printf '%s\n' \
  "repository_controls=PASS" \
  "security_updates=PASS" \
  "open_dependabot_alerts=${dependabot_alerts}" \
  "open_secret_alerts=${secret_alerts}" \
  "open_push_protection_bypass_alerts=${open_bypass_alerts}" \
  "open_dependabot_pull_requests=${dependabot_pull_requests}"
```

Never remove `hide_secret=true` from an operational secret-alert query. The command succeeds with non-zero counts so owners can triage them; a release requires every open item to have an approved disposition. Do not paste raw alert JSON, repository tokens, alert URLs, locations, commit details, or screenshots into public logs, issues, pull requests, or release evidence.

## Ownership And Triage

Before stable release or production cutover:

1. Assign a primary security-alert owner and a backup in the restricted operations record.
2. Confirm both owners have the minimum approved repository access and subscribe to GitHub security-alert notifications through an approved channel.
3. Record a read-only settings verification and aggregate alert counts for the exact release revision.
4. Review every open Dependabot, secret-scanning, code-scanning, and push-protection bypass alert. Do not bulk-dismiss alerts to make the gate pass.
5. Treat every secret alert as potentially compromised. Revoke or rotate at the issuer before repository cleanup or dismissal.
6. Require normal review, locked dependency audits, Quality checks, and compatibility tests for every Dependabot security pull request.
7. Investigate a paused security-update state, a disabled control, an unowned notification path, or an unreviewed bypass as a release blocker.

GitHub-native controls do not replace the response and rotation procedure in [Secret management and scanning](secret-management.md), the required Gitleaks history check, or the [public-content audit](public-content-audit.md).
