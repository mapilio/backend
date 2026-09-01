import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const specification = JSON.parse(await readFile(resolve(repositoryRoot, 'docs/api/openapi-v1.json'), 'utf8'));
const operation = specification.paths?.['/api/v1/imagery/leaderboard']?.get;

test('documents the versioned leaderboard route and its meaningful filters', () => {
    assert.ok(operation, 'GET /api/v1/imagery/leaderboard must be documented');
    assert.equal(operation.operationId, 'getImageryLeaderboard');
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), [
        { name: 'user_id', in: 'query', required: false },
        { name: 'start_at', in: 'query', required: false },
        { name: 'finish_at', in: 'query', required: false },
    ]);
    assert.equal(operation.parameters[0].allowEmptyValue, true);
    assert.match(operation.description, /data.*leaderboard/);
    assert.match(operation.description, /pagination metadata/);
    assert.match(operation.description, /reversed.*chronological order/);
    assert.match(operation.description, /public aggregate cache/);
});

test('keeps the legacy leaderboard envelope and row types explicit', () => {
    const responseSchema = specification.components.schemas.LeaderboardResponse;
    const rowSchema = specification.components.schemas.LeaderboardRow;

    assert.deepEqual(responseSchema.required, ['data']);
    assert.deepEqual(Object.keys(responseSchema.properties), ['data']);
    assert.deepEqual(responseSchema.properties.data.required, ['leaderboard']);
    assert.deepEqual(Object.keys(responseSchema.properties.data.properties), ['leaderboard']);
    assert.deepEqual(rowSchema.required, [
        'id',
        'username',
        'display_name',
        'user_profile_photo',
        'point',
        'total_length',
        'total_images',
        'roles',
    ]);
    assert.equal(rowSchema.properties.point.type, 'string');
    assert.equal(rowSchema.properties.total_length.type, 'string');
    assert.deepEqual(rowSchema.properties.roles.type, ['string', 'null']);
    assert.equal(rowSchema.properties.total_images.type, 'integer');
    assert.equal('pagination' in responseSchema.properties, false);
});

test('provides synthetic examples for every documented leaderboard response', () => {
    const responses = operation.responses;
    const examples = responses['200'].content['application/json'].examples;
    const requiredRowFields = specification.components.schemas.LeaderboardRow.required;

    for (const { value } of Object.values(examples)) {
        assert.ok(Array.isArray(value.data.leaderboard));
        for (const row of value.data.leaderboard) {
            assert.deepEqual(Object.keys(row).sort(), [...requiredRowFields].sort());
            assert.equal(typeof row.id, 'number');
            assert.equal(typeof row.point, 'string');
            assert.equal(typeof row.total_length, 'string');
            assert.equal(typeof row.total_images, 'number');
            assert.ok(row.roles === null || typeof row.roles === 'string');
        }
    }

    assert.deepEqual(responses['400'].content['application/json'].example, {
        success: false,
        message: ["'user_id' must be an integer!"],
        error_code: 400,
    });
    assert.deepEqual(responses['422'].content['application/json'].example, {
        message: 'The start at field must be a valid date.',
        errors: { start_at: ['The start at field must be a valid date.'] },
    });
});
