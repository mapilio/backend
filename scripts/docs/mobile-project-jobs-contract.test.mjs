import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const specification = JSON.parse(await readFile(resolve(repositoryRoot, 'docs/api/openapi-v1.json'), 'utf8'));
const operation = specification.paths?.['/api/v1/projects/jobs/mine']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = [
    'id',
    'sort_order',
    'created_at',
    'created_by_id',
    'updated_at',
    'updated_by_id',
    'deleted_at',
    'project_id',
    'project_key',
    'assign_id',
    'user_detail',
    'project_detail',
];
const userFields = ['id', 'username', 'email', 'display_name'];
const projectFields = [
    'marketplace_name',
    'marketplace_description',
    'project_organization_key',
    'project_key',
];

test('documents only the authenticated versioned GET jobs alias', () => {
    assert.ok(operation, 'GET /api/v1/projects/jobs/mine must be documented');
    assert.equal(specification.paths['/api/v1/projects/jobs/mine'].post, undefined);
    assert.equal(operation.operationId, 'getMobileProjectJobs');
    assert.deepEqual(operation.tags, ['Projects']);
    assert.deepEqual(operation.security, [{ mobileBearer: [] }]);
    assert.deepEqual(operation.parameters ?? [], []);
    assert.match(operation.description, /GET `\/api\/function\/projects\/job\/getMyJobs`/);
    assert.match(operation.description, /additive migration path/);
    assert.match(operation.description, /sort_order.*id/);
    assert.match(operation.description, /UTC ISO/);
});

test('locks the mobile project job response schemas and nullability', () => {
    assert.deepEqual(schemas.MobileProjectJobsResponse.required, ['data']);
    assert.deepEqual(Object.keys(schemas.MobileProjectJobsResponse.properties), ['data']);
    assert.deepEqual(schemas.MobileProjectJobsResponse.properties.data, {
        type: 'array',
        items: { $ref: '#/components/schemas/MobileProjectJobRow' },
    });
    assert.deepEqual(schemas.MobileProjectJobRow.required, rowFields);
    assert.deepEqual(Object.keys(schemas.MobileProjectJobRow.properties), rowFields);
    assert.deepEqual(schemas.MobileProjectJobRow.properties.id, { type: 'integer', format: 'int64' });
    assert.deepEqual(schemas.MobileProjectJobRow.properties.sort_order, { type: ['integer', 'null'] });
    assert.deepEqual(schemas.MobileProjectJobRow.properties.created_by_id, { type: ['integer', 'null'], format: 'int64' });
    assert.deepEqual(schemas.MobileProjectJobRow.properties.updated_by_id, { type: ['integer', 'null'], format: 'int64' });
    assert.deepEqual(schemas.MobileProjectJobRow.properties.project_id, { type: ['integer', 'null'], format: 'int64' });
    assert.deepEqual(schemas.MobileProjectJobRow.properties.assign_id, { type: ['integer', 'null'], format: 'int64' });
    for (const field of ['created_at', 'updated_at', 'deleted_at']) {
        assert.deepEqual(schemas.MobileProjectJobRow.properties[field], {
            type: ['string', 'null'],
            format: 'date-time',
            pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
        });
    }
    assert.deepEqual(schemas.MobileProjectJobUserDetail.required, userFields);
    assert.deepEqual(Object.keys(schemas.MobileProjectJobUserDetail.properties), userFields);
    assert.deepEqual(schemas.MobileProjectJobUserDetail.properties.id, { type: 'integer', format: 'int64' });
    for (const field of ['username', 'email', 'display_name']) {
        assert.deepEqual(schemas.MobileProjectJobUserDetail.properties[field], { type: ['string', 'null'] });
    }
    assert.deepEqual(schemas.MobileProjectJobProjectDetail.required, projectFields);
    assert.deepEqual(Object.keys(schemas.MobileProjectJobProjectDetail.properties), projectFields);
    for (const field of projectFields) {
        assert.deepEqual(schemas.MobileProjectJobProjectDetail.properties[field], { type: ['string', 'null'] });
    }
    assert.deepEqual(schemas.MobileProjectJobRow.properties.user_detail, {
        type: 'array',
        minItems: 1,
        maxItems: 1,
        items: { $ref: '#/components/schemas/MobileProjectJobUserDetail' },
    });
    assert.deepEqual(schemas.MobileProjectJobRow.properties.project_detail, {
        $ref: '#/components/schemas/MobileProjectJobProjectDetail',
    });
});

test('keeps synthetic populated, empty, and bearer failure examples exact', () => {
    const responses = operation.responses;
    const success = responses['200'].content['application/json'];

    assert.deepEqual(Object.keys(success.examples), ['populated', 'empty']);
    const populated = success.examples.populated.value;
    assert.equal(Array.isArray(populated.data), true);
    assert.equal(populated.data.length, 2);
    for (const row of populated.data) {
        assert.deepEqual(Object.keys(row), rowFields);
        assert.deepEqual(Object.keys(row.user_detail[0]), userFields);
        assert.deepEqual(Object.keys(row.project_detail), projectFields);
        if (row.created_at !== null) {
            assert.match(row.created_at, /^\d{4}-\d{2}-\d{2}T/);
        }
        if (row.updated_at !== null) {
            assert.match(row.updated_at, /^\d{4}-\d{2}-\d{2}T/);
        }
    }
    assert.equal(populated.data[0].deleted_at, null);
    assert.equal(populated.data[1].sort_order, null);
    assert.equal(populated.data[1].created_at, null);
    assert.equal(populated.data[1].updated_at, null);
    assert.equal(populated.data[1].project_detail.marketplace_description, null);
    assert.deepEqual(success.examples.empty.value, { data: [] });
    assert.deepEqual(responses['401'].content['application/json'].example, {
        message: 'Unauthenticated.',
    });
    assert.deepEqual(schemas.MobileProjectJobsUnauthenticatedError.properties.message, {
        const: 'Unauthenticated.',
    });
});
