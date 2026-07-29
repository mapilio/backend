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

## Source-Controlled Controls

The repository source now records the following planned or implemented controls:

- **Dependency Review:** `.github/workflows/dependency-review.yml` runs only for pull requests targeting `main`, with read-only contents access, a five-minute limit, PR-scoped concurrency, no checkout or code-execution step, and the pinned `actions/dependency-review-action` v5.0.0. It fails on high or critical severity and shows patched versions.
- **Dependency graph:** the repository-level graph is enabled so GitHub can inspect supported committed manifests and Dependency Review can compare pull-request changes. Automatic dependency submission remains disabled because the current Composer and npm manifests are already supported and no build-only ecosystem requirement has been approved.
- **Weekly version maintenance:** `.github/dependabot.yml` schedules distinct weekly UTC runs for Composer, npm, and GitHub Actions. Each ecosystem groups minor and patch updates, limits open version-update pull requests, and uses the conventional `deps` commit prefix. These version groups do not authorize merging. Dependabot security updates remain urgent and repository-setting-driven; owners must verify their enabled, unpaused live state.
- **CodeQL default setup:** native default setup is enabled for Actions and JavaScript/TypeScript only, using the default query suite, `remote_and_local` threat model, standard runner, and weekly schedule. PHP is unsupported by CodeQL here; PHPStan, tests, Composer audit, and the existing PHP quality gates remain required.
- **Workflow hardening:** repository policy requires full action SHAs, the default `GITHUB_TOKEN` is read-only, and workflows cannot approve pull requests.

The Dependency Review workflow does not grant pull-request comment or other write permissions. No control in this section changes organization-wide settings.

## Live Actions And CodeQL Settings

The July 29, 2026 pre-change audit found read-only default workflow permissions, workflow pull-request approval enabled, full-SHA enforcement disabled, and CodeQL not configured. The repository-level changes then:

- preserved read-only default workflow permissions
- disabled workflow pull-request approval
- enabled full-length action SHA enforcement
- enabled CodeQL default setup for Actions and JavaScript/TypeScript

Both initial CodeQL jobs completed successfully. The scan opened two high-severity findings: an incomplete hostname regular expression in a public-content regression test and an insufficiently bounded local fixture path in the GeoJSON validator. The source change that adds Dependency Review also corrects both findings without dismissal; closure requires a successful scan of the merged revision. After the repository Dependency Graph was enabled, the first read-only Dependency Review run passed.

Before release, owners must re-read these live settings, retain the source-controlled workflow and update policy, verify the exact CodeQL revision and alert dispositions, and record restricted notification ownership.

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

actions_policy=$(
  gh api repos/mapilio/backend/actions/permissions --jq '
    .enabled == true
    and .sha_pinning_required == true
  '
)
test "${actions_policy}" = true

workflow_policy=$(
  gh api repos/mapilio/backend/actions/permissions/workflow --jq '
    .default_workflow_permissions == "read"
    and .can_approve_pull_request_reviews == false
  '
)
test "${workflow_policy}" = true

codeql_setup=$(
  gh api repos/mapilio/backend/code-scanning/default-setup --jq '
    .state == "configured"
    and .query_suite == "default"
    and .threat_model == "remote_and_local"
    and .runner_type == "standard"
    and .schedule == "weekly"
  '
)
test "${codeql_setup}" = true

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

code_scanning_alerts=$(
  gh api --paginate \
    'repos/mapilio/backend/code-scanning/alerts?state=open&per_page=100' \
    --jq 'length' |
    awk '{ total += $1 } END { print total + 0 }'
)

printf '%s\n' \
  "repository_controls=PASS" \
  "security_updates=PASS" \
  "actions_policy=PASS" \
  "workflow_policy=PASS" \
  "codeql_setup=PASS" \
  "open_dependabot_alerts=${dependabot_alerts}" \
  "open_secret_alerts=${secret_alerts}" \
  "open_push_protection_bypass_alerts=${open_bypass_alerts}" \
  "open_dependabot_pull_requests=${dependabot_pull_requests}" \
  "open_code_scanning_alerts=${code_scanning_alerts}"
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
