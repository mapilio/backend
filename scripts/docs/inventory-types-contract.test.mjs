import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/inventory/types']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = [
    'id',
    'sort_order',
    'created_at',
    'created_by_id',
    'updated_at',
    'updated_by_id',
    'deleted_at',
    'code',
    'group_id',
    'icon',
    'name',
];

test('documents only the unauthenticated legacy-compatible inventory types GET', () => {
    assert.ok(operation, 'GET /api/v1/inventory/types must be documented');
    assert.equal(operation.operationId, 'getInventoryTypes');
    assert.deepEqual(operation.tags, ['Inventory']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), [
        { name: 'page', in: 'query', required: false },
        { name: 'locale', in: 'query', required: false },
    ]);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/get-types`/);
    assert.match(operation.description, /empty or out-of-range page.*\{data: null\}.*no pagination/s);
    assert.match(operation.description, /limit and offset of 100/);
    assert.match(operation.description, /`per_page` 15/);
    assert.match(operation.description, /end-of-pedestrians.*464\.5/);
    assert.match(operation.description, /options\[parameters\]\[group_id\]/);
    assert.match(operation.description, /no endpoint authentication, response cache, or ETag/);
    assert.match(operation.responses['429'].description, /deployment-configurable/);
});

test('locks inventory row, nullable metadata, and legacy pagination schemas', () => {
    assert.deepEqual(schemas.InventoryTypeRow.required, rowFields);
    assert.deepEqual(Object.keys(schemas.InventoryTypeRow.properties), rowFields);
    assert.deepEqual(schemas.InventoryTypeRow.properties.id, { type: 'integer', format: 'int64' });
    for (const field of ['sort_order', 'created_by_id', 'updated_by_id', 'group_id']) {
        assert.deepEqual(schemas.InventoryTypeRow.properties[field], { type: ['integer', 'null'] });
    }
    for (const field of ['created_at', 'updated_at']) {
        assert.deepEqual(schemas.InventoryTypeRow.properties[field], {
            type: ['string', 'null'],
            format: 'date-time',
            pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
        });
    }
    assert.deepEqual(schemas.InventoryTypeRow.properties.deleted_at, { type: 'null' });
    for (const field of ['code', 'icon', 'name']) {
        assert.deepEqual(schemas.InventoryTypeRow.properties[field], { type: ['string', 'null'] });
    }
    assert.deepEqual(schemas.InventoryTypesResponse.required, ['data']);
    assert.equal(schemas.InventoryTypesResponse.oneOf.length, 2);
    assert.deepEqual(schemas.InventoryTypesResponse.oneOf[0].required, ['data', 'pagination']);
    assert.equal(schemas.InventoryTypesResponse.oneOf[1].maxProperties, 1);
    assert.deepEqual(Object.keys(schemas.InventoryTypesResponse.properties), ['data', 'pagination']);
    assert.equal(schemas.InventoryTypesResponse.properties.data.oneOf[0].minItems, 1);
    assert.deepEqual(schemas.InventoryTypesPagination.properties.per_page, { type: 'integer', const: 15 });
    assert.deepEqual(schemas.InventoryTypesPagination.properties.path, { type: 'string', const: '/api/get-types' });
});

test('keeps synthetic populated, empty, and optional rate-limit examples exact', () => {
    const response = operation.responses['200'].content['application/json'];
    const populated = response.examples.populated.value;

    assert.deepEqual(Object.keys(response.examples), ['populated', 'empty']);
    assert.deepEqual(Object.keys(populated), ['data', 'pagination']);
    assert.deepEqual(populated.data.map((row) => Object.keys(row)), [rowFields, rowFields, rowFields]);
    assert.deepEqual(populated.data.map(({ id, code }) => ({ id, code })), [
        { id: 464, code: 'crossing' },
        { id: 500, code: 'end-of-pedestrians' },
        { id: 465, code: 'pedestrian-crossing' },
    ]);
    assert.match(populated.data[0].created_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
    assert.equal(populated.data[0].deleted_at, null);
    assert.equal(populated.data[1].sort_order, null);
    assert.equal(populated.data[1].name, null);
    assert.equal(populated.pagination.to, 3);
    assert.equal(populated.pagination.total, 3);
    assert.equal(populated.pagination.per_page, 15);
    assert.equal(populated.pagination.path, '/api/get-types');
    assert.deepEqual(response.examples.empty.value, { data: null });
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('guards documentation against route, query, and focused PHP contract drift', async () => {
    const [routes, controller, query, compatibility] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Inventory/TypeMetadataController.php'),
        readText('app/Domain/InventoryFeatures/Queries/TypeMetadataQuery.php'),
        readText('tests/Feature/Legacy/TypeMetadataCompatibilityTest.php'),
    ]);

    assert.match(routes, /Route::get\('get-types', \[TypeMetadataController::class, 'types'\]\)/);
    assert.match(routes, /Route::get\('inventory\/types', \[TypeMetadataController::class, 'types'\]\)/);
    assert.doesNotMatch(routes, /Route::get\('inventory\/types',[\s\S]{0,180}->middleware\(/);
    assert.match(controller, /return response\(\)->json\(\$query->types\(\$request\)\)/);
    assert.match(query, /private const DATA_PAGE_SIZE = 100/);
    assert.match(query, /private const LEGACY_PAGINATION_SIZE = 15/);
    assert.match(query, /max\(1, \(int\) \$request->query\('page', 1\)\)/);
    assert.match(query, /is_string\(\$locale\) && \$locale !== '' \? \$locale : 'en'/);
    assert.match(query, /CASE WHEN \$table\.code = \? THEN 464\.5 ELSE \$table\.id END ASC/);
    assert.match(query, /return \['data' => null\]/);
    assert.match(query, /'\/api\/get-types'/);
    assert.match(compatibility, /test_versioned_types_rows_preserve_exact_order_scalar_types_and_nullability/);
    assert.match(compatibility, /test_page_two_uses_a_hundred_row_offset_but_fifteen_row_legacy_metadata/);
});
