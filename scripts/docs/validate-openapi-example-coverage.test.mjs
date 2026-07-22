import test from 'node:test';
import assert from 'node:assert/strict';

import { findExampleCoverageIssues, validateExampleCoverage } from './validate-openapi-example-coverage.mjs';

const media = (extra = {}) => ({ schema: { type: 'object' }, ...extra });
const operation = (content) => ({ post: { requestBody: { content }, responses: {} } });

test('passes an inline example and examples map with values', () => {
    const specification = {
        paths: {
            '/synthetic': operation({
                'application/json': media({ example: { ok: true } }),
                'application/problem+json': media({ examples: { failure: { summary: 'Synthetic', value: { ok: false } } } }),
            }),
        },
    };

    assert.equal(validateExampleCoverage(specification), true);
    assert.deepEqual(findExampleCoverageIssues(specification), []);
});

test('reports missing request and response examples with JSON-pointer locations', () => {
    const specification = {
        paths: {
            '/items/{item/id}': {
                get: {
                    responses: {
                        '200': { content: { 'application/json': media() } },
                    },
                },
            },
        },
    };

    const issues = findExampleCoverageIssues(specification);
    assert.deepEqual(issues.map(({ pointer }) => pointer), [
        '/paths/~1items~1{item~1id}/get/responses/200/content/application~1json',
    ]);
    assert.throws(() => validateExampleCoverage(specification), /schema has no inline example/);
});

test('rejects empty examples maps and entries without value', () => {
    const specification = {
        paths: {
            '/synthetic': operation({
                'application/json': media({ examples: {} }),
                'application/problem+json': media({ examples: { missing: { summary: 'No value' } } }),
            }),
        },
    };

    assert.deepEqual(findExampleCoverageIssues(specification).map(({ pointer }) => pointer), [
        '/paths/~1synthetic/post/requestBody/content/application~1json/examples',
        '/paths/~1synthetic/post/requestBody/content/application~1problem+json/examples/missing',
    ]);
});

test('resolves reusable requestBody and response components before checking coverage', () => {
    const specification = {
        paths: {
            '/reusable': {
                post: {
                    requestBody: { $ref: '#/components/requestBodies/SyntheticRequest' },
                    responses: {
                        '200': { $ref: '#/components/responses/SyntheticResponse' },
                    },
                },
            },
        },
        components: {
            requestBodies: {
                SyntheticRequest: {
                    content: {
                        'application/json': media({ example: { request: true } }),
                    },
                },
            },
            responses: {
                SyntheticResponse: {
                    content: {
                        'application/json': media({ example: { response: true } }),
                    },
                },
            },
        },
    };

    assert.deepEqual(findExampleCoverageIssues(specification), []);
});

test('resolves reusable local Example Objects in examples maps', () => {
    const specification = {
        paths: {
            '/reusable-example': operation({
                'application/json': media({
                    examples: {
                        success: { $ref: '#/components/examples/SyntheticSuccess' },
                    },
                }),
            }),
        },
        components: {
            examples: {
                SyntheticSuccess: { summary: 'Synthetic success', value: { ok: true } },
            },
        },
    };

    assert.deepEqual(findExampleCoverageIssues(specification), []);
});

test('reports missing and cyclic local operation references', () => {
    const specification = {
        paths: {
            '/bad-refs': {
                post: {
                    requestBody: { $ref: '#/components/requestBodies/Missing' },
                    responses: {
                        '200': { $ref: '#/components/responses/CycleA' },
                    },
                },
            },
        },
        components: {
            responses: {
                CycleA: { $ref: '#/components/responses/CycleB' },
                CycleB: { $ref: '#/components/responses/CycleA' },
            },
        },
    };

    const issues = findExampleCoverageIssues(specification);
    assert.deepEqual(issues.map(({ pointer }) => pointer), [
        '/paths/~1bad-refs/post/requestBody/$ref',
        '/paths/~1bad-refs/post/responses/200/$ref',
    ]);
    assert.match(issues[0].message, /does not resolve/);
    assert.match(issues[1].message, /cyclic local reference/);
});

test('rejects externalValue examples for deterministic offline coverage', () => {
    const specification = {
        paths: {
            '/external-example': operation({
                'application/json': media({
                    examples: {
                        external: { externalValue: 'https://example.test/synthetic.json' },
                    },
                }),
            }),
        },
    };

    const issues = findExampleCoverageIssues(specification);
    assert.equal(issues.length, 1);
    assert.match(issues[0].message, /externalValue is not supported/);
    assert.match(issues[0].message, /repository-visible/);
});

test('reports direct null example entries exactly once', () => {
    const specification = {
        paths: {
            '/direct-null': operation({
                'application/json': media({ examples: { nullEntry: null } }),
            }),
        },
    };

    const issues = findExampleCoverageIssues(specification);
    assert.equal(issues.length, 1);
    assert.equal(issues[0].pointer, '/paths/~1direct-null/post/requestBody/content/application~1json/examples/nullEntry');
    assert.match(issues[0].message, /Example Object with a value/);
});

test('reports a local reference resolving to null exactly once', () => {
    const specification = {
        paths: {
            '/referenced-null': operation({
                'application/json': media({
                    examples: {
                        nullEntry: { $ref: '#/components/examples/NullExample' },
                    },
                }),
            }),
        },
        components: {
            examples: {
                NullExample: null,
            },
        },
    };

    const issues = findExampleCoverageIssues(specification);
    assert.equal(issues.length, 1);
    assert.equal(issues[0].pointer, '/paths/~1referenced-null/post/requestBody/content/application~1json/examples/nullEntry');
    assert.match(issues[0].message, /Example Object with a value/);
});
