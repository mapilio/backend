# ADR 0036: Reviewed linear main

- Status: Accepted
- Date: 2026-08-04
- Extends: [ADR 0034 - Protected main and required GitHub checks](0034-protected-main-required-checks.md)

## Decision

The intended reviewed-linear branch-protection policy for `main` requires:

- at least one approving review;
- approval from a CODEOWNERS maintainer;
- dismissal of stale approvals after a new push;
- approval of the most recent push by a reviewer other than that push author;
- resolution of every pull-request conversation;
- a linear history;
- the existing five strict GitHub-Actions-bound checks, with the branch up to date before they satisfy the merge gate; and
- no force-pushes or branch deletion.

The five checks remain:

1. `PHP style, analysis, audit, and tests`;
2. `OpenAPI contract, npm audit, and asset build`;
3. `PostgreSQL 14 and PostGIS migrations`;
4. `Gitleaks history`; and
5. `Dependency Review`.

## Merge settings

Under this policy, merge commits must be disabled; squash and rebase merges must be allowed; the update-branch option must be enabled; and merged branches must be deleted automatically. Squash must use the pull-request title as its default title and leave the generated body blank. Maintainers must supply sanitized natural-language body text when a body is needed; generated or sensitive text must not enter public history.

This ADR and the repository's CODEOWNERS file define the source-controlled policy. They do not prove that GitHub enforces it. The live GitHub branch-protection and repository-settings APIs are the activation source of truth.

## Live-state reconciliation

On 2026-08-04, the owner approved a temporary solo-maintainer policy while independent review capacity is unavailable. The required pull-request review object remains present, but the live branch-protection API reports:

- `required_pull_request_reviews.required_approving_review_count: 0`;
- `required_pull_request_reviews.require_code_owner_reviews: false`;
- `required_pull_request_reviews.dismiss_stale_reviews: false`; and
- `required_pull_request_reviews.require_last_push_approval: false`.

This temporary owner-approved exception is not equivalent to independent review and does not establish security readiness, governance readiness, or release readiness. The global CODEOWNERS rule is intentionally limited to `@ozcan-durak` so future pull requests do not automatically request `@fatihalp` or `@gorkemgul`.

The remaining live protections stay enabled: strict up-to-date status checks with exactly the five required checks listed below, conversation resolution, linear history, and disabled force-pushes and branch deletion. Administrator enforcement and required signatures remain disabled.

The reconciliation records PR #16 as merged at `eaa49eb`, PR #17 as merged at `2770479`, and PR #18 as merged at `d63bdf0`. These are historical merge records, not evidence of independent review or governance readiness.

## Verification

Roll out and verify the policy in this order:

1. Merge CODEOWNERS and this policy under the existing five-check protection from [ADR 0034](0034-protected-main-required-checks.md). This bootstrap sequence follows the current merge gate and is not an administrator bypass.
2. Apply the reviewed-linear branch protection and repository merge settings.
3. Read the live GitHub APIs and verify the exact repository and `main` branch report all expected outcomes below. Source-controlled policy alone is insufficient evidence of activation.

The merged default branch must contain the temporary global CODEOWNERS rule for `@ozcan-durak` only. The branch-protection API must report the reconciled review object above, plus:

- `required_conversation_resolution.enabled: true`;
- `required_linear_history.enabled: true`;
- `required_status_checks.strict: true`, with exactly `PHP style, analysis, audit, and tests`, `OpenAPI contract, npm audit, and asset build`, `PostgreSQL 14 and PostGIS migrations`, `Gitleaks history`, and `Dependency Review`, each bound to the GitHub Actions application;
- `allow_force_pushes.enabled: false`;
- `allow_deletions.enabled: false`;
- `enforce_admins.enabled: false`; and
- required signatures `enabled: false`.

The repository settings API must report:

- `allow_merge_commit: false`;
- `allow_squash_merge: true`;
- `allow_rebase_merge: true`;
- `allow_update_branch: true`;
- `delete_branch_on_merge: true`;
- `squash_merge_commit_title: "PR_TITLE"`; and
- `squash_merge_commit_message: "BLANK"`.

## Emergency and deferred controls

Administrator enforcement remains disabled. There is currently one administrator, and no signing or recovery drill exists. Signed commits therefore remain disabled until a second administrator, signing policy, and tested recovery evidence exist.

An administrator bypass is emergency-only. Each bypass requires a restricted incident/recovery record containing the exact revision, reason, follow-up review, and relevant recovery evidence. It must never be used for routine work or dependency convenience.

The second-administrator assignment, signed-commit policy, administrator enforcement, and restricted role/recovery evidence remain open owner decisions. They must be recorded outside this public repository without private contact details.

## Administrator/signing readiness milestone

The public [administrator/signing readiness runbook](../security/github-administrator-signing-readiness.md) includes a dependency-free live-state matcher for this gate. It reads each global CODEOWNER's permission and `main` protection through read-only GitHub APIs, emits only the aggregate global CODEOWNER administrator count and control booleans, and fails closed on malformed data or unsafe partial activation. Administrators outside the global CODEOWNERS rule deliberately do not satisfy the count; the selected second emergency administrator must remain in that rule for this gate.

An exact `deferred`, `signing`, or `enforced` state match does not validate independent account control, 2FA/recovery, signing custody, drill/evidence, the bot alternative, owner approval, or target-revision signoff. The matcher does not grant permissions, mutate settings, or declare governance readiness.

Required signatures and administrator enforcement stay disabled until a restricted owner decision, an independently controlled second administrator with tested recovery, signing/key-custody evidence, and the non-production drill pass. Administrator enforcement remains a separate owner decision after signing compatibility passes. GitHub's signed-commit limitation for UI squash merges means the current maintainer-squashed Dependabot workflow requires an approved alternative before activation.

## Relationship to ADR 0034

This ADR supersedes and extends the review portion of [ADR 0034](0034-protected-main-required-checks.md) without rewriting its history. ADR 0034 remains the record of the original strict-check and no-force-push/deletion decision; this ADR adds reviewed, conversation, linear-history, merge-setting, and emergency-bypass governance.
