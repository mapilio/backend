## Summary

<!-- What changes, and which user/API/operational problem does it solve? -->

## Compatibility And Risk

<!-- Cover affected consumers, response contracts, auth/privacy, schema, queues, external services, flags, and rollback/disable behavior. -->

## Verification

<!-- List targeted tests and the complete local gate. Use sanitized evidence only. -->

## Checklist

- [ ] The change is scoped to one coherent problem and links its issue or ADR.
- [ ] Public behavior is versioned or an existing compatibility contract is preserved by tests.
- [ ] OpenAPI and documentation are updated when behavior changes.
- [ ] Validation, authorization, safe failures, idempotency, and retry behavior are covered where relevant.
- [ ] No secret, personal data, production record, real imagery, private coordinate, log, or infrastructure detail is included.
- [ ] External side effects remain disabled or have approved staging evidence and rollback.
- [ ] `scripts/release/verify-local-readiness.sh` passes.
- [ ] Unfinished staging/production gates are described accurately and not marked complete.
