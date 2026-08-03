# ADR 0036: Reviewed linear main

- Status: Accepted
- Date: 2026-08-04
- Extends: [ADR 0034 - Protected main and required GitHub checks](0034-protected-main-required-checks.md)

## Decision

The accepted branch-protection policy for `main` requires:

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

## Verification

Roll out and verify the policy in this order:

1. Merge CODEOWNERS and this policy under the existing five-check protection from [ADR 0034](0034-protected-main-required-checks.md). This bootstrap sequence follows the current merge gate and is not an administrator bypass.
2. Apply the reviewed-linear branch protection and repository merge settings.
3. Read the live GitHub APIs and verify the exact repository and `main` branch report all expected outcomes below. Source-controlled policy alone is insufficient evidence of activation.

The merged default branch must contain the global CODEOWNERS rule for the three repository maintainers. The branch-protection API must report:

- `required_pull_request_reviews.required_approving_review_count: 1`;
- `required_pull_request_reviews.require_code_owner_reviews: true`;
- `required_pull_request_reviews.dismiss_stale_reviews: true`;
- `required_pull_request_reviews.require_last_push_approval: true`;
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

## Relationship to ADR 0034

This ADR supersedes and extends the review portion of [ADR 0034](0034-protected-main-required-checks.md) without rewriting its history. ADR 0034 remains the record of the original strict-check and no-force-push/deletion decision; this ADR adds reviewed, conversation, linear-history, merge-setting, and emergency-bypass governance.
