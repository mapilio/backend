# GitHub administrator and signed-commit readiness

Status: tooling and public runbook complete; activation deferred.

This runbook includes a fail-closed live-state matcher, not an automated governance-readiness decision or permission-change procedure. The matcher measures only the global CODEOWNER administrator count and two `main` protection booleans. It never grants access, changes repository settings, creates keys, publishes evidence, or declares approval. The fixed live repository is `mapilio/backend`, and the protected branch is `main`.

## Sanitized point-in-time facts

As of 2026-08-04, the current source-controlled `.github/CODEOWNERS` global rule names three maintainers. A point-in-time read-only live audit found exactly one repository administrator and two users with write access. At that audit point, required signatures on `main` were `false`, administrator enforcement was `false`, and the latest ten `main` commits were GitHub verified. These are sanitized observations at a point in time; they are not proof that the readiness gates have passed and must be rechecked before any owner decision.

## Restricted owner decision and evidence

Before activation, owners must decide and record in a restricted system:

- an independently controlled second repository administrator account, with secure 2FA and tested recovery methods;
- the signing method, key custody, recovery authority, expiry/rotation approach, and separation from ordinary workstation access;
- emergency roles, including who may authorize a bypass, who performs recovery, and who independently reviews it;
- timestamps, exact source revision, exact repository/branch settings, pass/fail results, rollback authority, and follow-up owner decisions.

The restricted record may contain identity, contact, key, token, recovery, or provider evidence. None of that evidence belongs in this repository: do not publish private identities, contacts, keys, tokens, screenshots, or sensitive artifacts.

The selected second emergency administrator must remain in the single global CODEOWNERS rule for this gate. Administrators outside that rule deliberately do not satisfy its measured administrator-count condition.

## Non-production drill

The drill uses a temporary branch and temporary rule that never weakens or replaces `main`. It must be independently observed by the second administrator and cleaned up afterward. Record only sanitized outcomes publicly.

1. Create the temporary branch/rule from the exact reviewed revision. Confirm `main` protection and both live controls remain unchanged.
2. Test a signed push and signed pull request, then test rejection of an unsigned commit.
3. Test a web-authored commit and a human-authored pull request. Test the Dependabot/bot path with the repository's actual approved workflow.
4. Have the second administrator independently verify the branch rule, signatures, reviews, status, audit timestamps, and observed pass/fail results.
5. Exercise recovery and rollback: revoke or isolate the test signing path, restore the temporary rule to its prior state, and confirm the protected `main` path remains safe.
6. Delete the temporary branch/rule and confirm cleanup. Preserve sensitive evidence only in the restricted record.

GitHub documents an important compatibility limitation: when required signed commits are enabled, the GitHub UI squash-merge option is available only to the pull request author. The current maintainer-squashed Dependabot workflow therefore needs an approved alternative before required signatures can be activated. See GitHub's [protected branch documentation](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches) and [commit-signature documentation](https://docs.github.com/en/authentication/managing-commit-signature-verification).

## Activation and rollback

Keep required signatures and administrator enforcement disabled until the second administrator, restricted evidence, and every drill gate pass. Activation order is:

1. Complete the restricted owner decision and independently controlled administrator/recovery evidence.
2. Complete and pass the non-production drill, including the Dependabot/bot alternative.
3. Re-read each global CODEOWNER's permission, CODEOWNERS, branch protection, and required signatures with `--expect=deferred`; require `SAFE_DEFERRED` before changing a control. Separately bind the exact target revision to the restricted owner record because the matcher does not fetch or validate a branch SHA.
4. Enable required signed commits, then verify the live booleans and global CODEOWNER administrator count with `--expect=signing`; require `SIGNING_STATE_MATCHED`. Separately verify the signed normal workflow. Do not enable administrator enforcement in the same decision.
5. After a separate owner decision and review, optionally enable administrator enforcement and verify the measured live state independently with `--expect=enforced`; require `ENFORCED_STATE_MATCHED`.

If any verification fails, or recovery cannot be demonstrated, stop. Roll back administrator enforcement first and require the `signing` state match; if signing must also be rolled back, disable required signatures and require the `deferred` state match. Preserve the restricted incident record and re-run the read-only checks. If either control is enabled with fewer than two global CODEOWNER administrators, the matcher always fails as unsafe partial activation; do not use an administrator bypass for routine or dependency work.

## Dependency-free verifier

The focused offline test is `npm run test:github-administrator-signing-readiness`. The simple manual default is `npm run check:github-administrator-signing-state`, which performs read-only, API-version-pinned `gh api` calls and checks the deferred state:

```text
npm run check:github-administrator-signing-state
node scripts/governance/verify-github-administrator-signing-readiness.mjs --expect=signing
node scripts/governance/verify-github-administrator-signing-readiness.mjs --expect=enforced
```

The exact measured-state modes are:

- `--expect=deferred`: at least one global CODEOWNER administrator, required signatures disabled, and administrator enforcement disabled; success emits `SAFE_DEFERRED`;
- `--expect=signing`: at least two global CODEOWNER administrators, required signatures enabled, and administrator enforcement disabled; success emits `SIGNING_STATE_MATCHED`;
- `--expect=enforced`: at least two global CODEOWNER administrators with required signatures and administrator enforcement enabled; success emits `ENFORCED_STATE_MATCHED`.

Any mismatch emits `STATE_MISMATCH`, reports a positive aggregate error count, and exits nonzero. Either live control enabled with fewer than two global CODEOWNER administrators is always unsafe. Each `gh` subprocess is bounded to 30 seconds and fails closed without exposing command stderr. Output is limited to aggregate counts and booleans; CODEOWNER handles are never printed.

A matched state does not validate independent account control, 2FA or recovery, signing-key custody, drill or restricted evidence, the bot/Dependabot alternative, owner approval, or target-revision signoff. `GOVERNANCE_READINESS_VALIDATED=false` is therefore always printed. The live command must not run in CI.
