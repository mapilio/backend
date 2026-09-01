import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readJson = async (relativePath) => JSON.parse(await readFile(resolve(repositoryRoot, relativePath), 'utf8'));
const readText = async (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');

const specification = await readJson('docs/api/openapi-v1.json');
const operation = specification.paths['/api/v1/imagery/user-uploads']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = ['total', 'uploaded_hash', 'capture_time', 'cover_photo', 'group_key', 'start_address', 'last_status'];
const paginationFields = ['current_page', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'];

test('documents the versioned user uploads request contract', () => {
    assert.ok(operation);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), [
        { name: 'options[parameters][user_id]', in: 'query', required: true },
        { name: 'options[limit]', in: 'query', required: false },
        { name: 'page', in: 'query', required: false },
    ]);
    assert.deepEqual(operation.parameters.map(({ schema }) => schema), [
        { type: 'number', minimum: 1 },
        { type: ['number', 'string'], default: 15 },
        { type: ['number', 'string'], default: 1 },
    ]);
    assert.match(operation.parameters[0].description, /Decimal numeric forms are accepted and truncated by that integer cast/);
    assert.match(operation.parameters[1].description, /PHP integer-casts the raw value, then clamps the result to the inclusive range 1\.\.1000/);
    assert.match(operation.parameters[2].description, /PHP integer-casts the raw value, then clamps the result to at least 1/);
    for (const parameter of operation.parameters.slice(1)) {
        assert.equal(Object.hasOwn(parameter.schema, 'minimum'), false);
        assert.equal(Object.hasOwn(parameter.schema, 'maximum'), false);
    }
    assert.match(operation.description, /Maintained mobile clients currently use `\/api\/user-uploads-v2`/);
    assert.match(operation.description, /Pagination link values intentionally retain the legacy `\/api\/user-uploads-v2` path/);
});

test('documents the exact row and Laravel pagination shapes', () => {
    assert.deepEqual(schemas.UserUploadsRow.required, rowFields);
    assert.deepEqual(Object.keys(schemas.UserUploadsRow.properties).sort(), [...rowFields].sort());
    assert.deepEqual(schemas.UserUploadsRow.properties, {
        total: { type: 'integer', minimum: 1 },
        uploaded_hash: { type: ['string', 'null'] },
        capture_time: { type: ['string', 'null'] },
        cover_photo: { type: ['string', 'null'] },
        group_key: { type: ['string', 'null'] },
        start_address: { type: ['string', 'null'] },
        last_status: { type: ['string', 'null'] },
    });
    assert.deepEqual(schemas.UserUploadsPagination.required, paginationFields);
    assert.equal(schemas.UserUploadsPagination.properties.links.items.$ref, '#/components/schemas/UserUploadsPaginationLink');
    assert.deepEqual(schemas.UserUploadsPaginationLink.required, ['url', 'label', 'active']);
    assert.deepEqual(schemas.UserUploadsResponse.properties.data.oneOf, [
        { type: 'array', minItems: 1, items: { $ref: '#/components/schemas/UserUploadsRow' } },
        { type: 'null' },
    ]);
});

test('keeps synthetic examples aligned with legacy pagination links and null pages', () => {
    const response = operation.responses['200'].content['application/json'];
    assert.deepEqual(Object.keys(response.examples), ['pageWithRows', 'empty', 'outOfRange']);

    const populated = response.examples.pageWithRows.value;
    assert.deepEqual(Object.keys(populated.data[0]).sort(), [...rowFields].sort());
    assert.equal(populated.data[1].start_address, null);
    assert.equal(populated.data[1].last_status, null);
    assert.equal(populated.pagination.path, '/api/user-uploads-v2');
    assert.equal(populated.pagination.first_page_url, '/api/user-uploads-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Blimit%5D=2&page=1');
    assert.deepEqual(populated.pagination.links, [
        { url: null, label: '&laquo; Previous', active: false },
        { url: '/api/user-uploads-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Blimit%5D=2&page=1', label: '1', active: true },
        { url: null, label: 'Next &raquo;', active: false },
    ]);

    for (const name of ['empty', 'outOfRange']) {
        const example = response.examples[name].value;
        assert.equal(example.data, null, `${name} example must retain data:null`);
        assert.equal(example.pagination.path, '/api/user-uploads-v2');
        assert.ok(example.pagination.first_page_url.startsWith('/api/user-uploads-v2?options%5Bparameters%5D%5Buser_id%5D='));
    }
    assert.equal(response.examples.empty.value.pagination.total, 0);
    assert.equal(response.examples.outOfRange.value.pagination.current_page, 2);
    assert.equal(response.examples.outOfRange.value.pagination.from, null);
    assert.equal(response.examples.outOfRange.value.pagination.to, null);
});

test('freezes the object-shaped 400 message without an error code', () => {
    const response = operation.responses['400'].content['application/json'];
    assert.deepEqual(response.example, {
        success: false,
        message: { user_id: ['The user_id field is required.'] },
    });
    assert.equal(Object.hasOwn(response.example, 'error_code'), false);
    assert.deepEqual(schemas.UserUploadsValidationError.required, ['success', 'message']);
    assert.equal(Object.hasOwn(schemas.UserUploadsValidationError.properties, 'error_code'), false);
});

test('guards documented claims against the route, controller, query, and fixture drift', async () => {
    const [routes, controller, query, fixture, compatibilityDocs] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Imagery/UserUploadsController.php'),
        readText('app/Domain/ImagerySequences/Queries/UserUploadsQuery.php'),
        readText('tests/Feature/Legacy/UserUploadsCompatibilityTest.php'),
        readText('docs/api/public-message-compatibility.md'),
    ]);

    assert.match(routes, /Route::get\('imagery\/user-uploads', UserUploadsController::class\)/);
    assert.match(controller, /data_get\(\$request->query\(\), 'options\.parameters\.user_id'\)/);
    assert.match(controller, /'user_id' => \['The user_id field is required\.'\]/);
    assert.match(query, /data' => \$rows === \[\] \? null : \$rows/);
    assert.match(query, /data_get\(\$request->query\(\), 'options\.limit', 15\)/);
    assert.match(query, /max\(1, min\(1000/);
    assert.match(query, /\/api\/user-uploads-v2/);
    assert.match(fixture, /options\[parameters\]\[user_id\]/);
    assert.match(fixture, /\/api\/v1\/imagery\/user-uploads/);
    assert.match(compatibilityDocs, /`GET \/api\/user-uploads-v2`, `GET \/api\/v1\/imagery\/user-uploads`/);
});
