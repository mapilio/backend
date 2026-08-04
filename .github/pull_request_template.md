## Summary

<!-- What changes, and which user/API/operational problem does it solve? -->

## Compatibility And Risk

<!-- Cover affected consumers, response contracts, auth/privacy, schema, queues, external services, flags, and rollback/disable behavior. -->

## Verification

<!-- List targeted tests and the complete local gate. Use sanitized evidence only. -->

## Governance note

This repository is operating under a temporary owner-approved solo-maintainer policy. It permits owner-approved merges without independent approval; that is not equivalent to independent review and does not establish security or governance readiness. Record any unavailable independent review and the remaining release gates accurately in this pull request.

## Checklist

- [ ] The change is scoped to one coherent problem and links its issue or ADR.
- [ ] Public behavior is versioned or an existing compatibility contract is preserved by tests.
- [ ] OpenAPI and documentation are updated when behavior changes.
- [ ] Validation, authorization, safe failures, idempotency, and retry behavior are covered where relevant.
- [ ] All review conversations are resolved, including conversations reopened by later pushes.
- [ ] The merge will preserve linear history and uses sanitized natural-language title/body text; no generated or sensitive text is being carried into the public history.
- [ ] Any emergency administrator bypass has its exact revision, reason, and follow-up review recorded in the restricted system; no restricted record or evidence is included in this public pull request.
- [ ] No secret, personal data, production record, real imagery, private coordinate, log, or infrastructure detail is included.
- [ ] External side effects remain disabled or have approved staging evidence and rollback.
- [ ] `scripts/release/verify-local-readiness.sh` passes.
- [ ] Unfinished staging/production gates are described accurately and not marked complete.
