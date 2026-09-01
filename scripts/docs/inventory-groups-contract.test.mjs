import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/inventory/groups']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = [
    'id',
    'sort_order',
    'created_at',
    'created_by_id',
    'updated_at',
    'updated_by_id',
    'deleted_at',
    'slug',
    'name',
];
const paginationFields = [
    'current_page',
    'first_page_url',
    'from',
    'last_page',
    'last_page_url',
    'links',
    'next_page_url',
    'path',
    'per_page',
    'prev_page_url',
    'to',
    'total',
];
const rateLimitHeaders = {
    'Retry-After': {
        description: 'Decimal number of seconds until another request may be attempted.',
        schema: { type: 'string', pattern: '^[0-9]+$' },
    },
    'X-RateLimit-Limit': {
        description: 'Configured maximum requests in the current limiter window, serialized as a decimal header value.',
        schema: { type: 'string', pattern: '^[1-9][0-9]*$' },
    },
    'X-RateLimit-Remaining': {
        description: 'Requests remaining in the current limiter window, serialized as a decimal header value.',
        schema: { const: '0' },
    },
};

test('documents only the unauthenticated legacy-compatible inventory groups GET', () => {
    assert.ok(operation, 'GET /api/v1/inventory/groups must be documented');
    assert.equal(specification.paths['/api/get-groups'], undefined);
    assert.equal(operation.operationId, 'getInventoryGroups');
    assert.deepEqual(operation.tags, ['Inventory']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), [
        { name: 'page', in: 'query', required: false },
        { name: 'locale', in: 'query', required: false },
    ]);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/get-groups`/);
    assert.match(operation.description, /active `default_types_groups` rows.*`deleted_at` is null/is);
    assert.match(operation.description, /left-joined to the locale translation/);
    assert.match(operation.description, /ordering is by `sort_order` only, so ties have unspecified order/);
    assert.match(operation.description, /invalid stored timestamps become null/);
    assert.match(operation.description, /empty or out-of-range page.*\{data: null\}.*no pagination/s);
    assert.match(operation.description, /limit and offset of 100/);
    assert.match(operation.description, /`per_page` 15/);
    assert.match(operation.description, /`http_build_query` after overwriting `page`/);
    assert.match(operation.description, /bounded to numeric and ellipsis entries/);
    assert.match(operation.description, /no endpoint authentication, response cache, or ETag/);
    assert.doesNotMatch(operation.description, /mobile/i);
    assert.doesNotMatch(operation.description, /production (?:table|data)/i);
    assert.match(operation.responses['429'].description, /deployment-configurable/);
});

test('locks inventory group row, pagination, and response schemas', () => {
    assert.deepEqual(schemas.InventoryGroupRow.required, rowFields);
    assert.deepEqual(Object.keys(schemas.InventoryGroupRow.properties), rowFields);
    assert.deepEqual(schemas.InventoryGroupRow.properties.id, { type: 'integer', format: 'int64' });
    for (const field of ['sort_order', 'created_by_id', 'updated_by_id']) {
        assert.deepEqual(schemas.InventoryGroupRow.properties[field], { type: ['integer', 'null'] });
    }
    for (const field of ['created_at', 'updated_at']) {
        assert.deepEqual(schemas.InventoryGroupRow.properties[field], {
            type: ['string', 'null'],
            format: 'date-time',
            pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
        });
    }
    assert.deepEqual(schemas.InventoryGroupRow.properties.deleted_at, { type: 'null' });
    for (const field of ['slug', 'name']) {
        assert.deepEqual(schemas.InventoryGroupRow.properties[field], { type: ['string', 'null'] });
    }

    assert.deepEqual(schemas.GroupsPagination.required, paginationFields);
    assert.deepEqual(Object.keys(schemas.GroupsPagination.properties), paginationFields);
    assert.deepEqual(schemas.GroupsPagination.properties.links.items, {
        $ref: '#/components/schemas/InventoryTypesPaginationLink',
    });
    assert.deepEqual(schemas.GroupsPagination.properties.path, { type: 'string', const: '/api/get-groups' });
    assert.deepEqual(schemas.GroupsPagination.properties.per_page, { type: 'integer', const: 15 });
    assert.deepEqual(schemas.GroupsResponse.required, ['data']);
    assert.equal(schemas.GroupsResponse.oneOf.length, 2);
    assert.deepEqual(schemas.GroupsResponse.oneOf[0].required, ['data', 'pagination']);
    assert.equal(schemas.GroupsResponse.oneOf[1].maxProperties, 1);
    assert.deepEqual(Object.keys(schemas.GroupsResponse.properties), ['data', 'pagination']);
    assert.equal(schemas.GroupsResponse.properties.data.oneOf[0].minItems, 1);
});

test('keeps synthetic populated, empty, and optional rate-limit examples exact', () => {
    const response = operation.responses['200'].content['application/json'];
    const populated = response.examples.populated.value;

    assert.deepEqual(Object.keys(response.examples), ['populated', 'empty']);
    assert.deepEqual(Object.keys(populated), ['data', 'pagination']);
    assert.deepEqual(populated.data.map((row) => Object.keys(row)), [rowFields, rowFields, rowFields]);
    assert.deepEqual(populated.data.map(({ id, slug }) => ({ id, slug })), [
        { id: 101, slug: 'traffic-signs' },
        { id: 102, slug: 'objects' },
        { id: 103, slug: null },
    ]);
    assert.match(populated.data[0].created_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
    assert.equal(populated.data[0].deleted_at, null);
    assert.equal(populated.data[1].name, null);
    assert.equal(populated.data[2].sort_order, null);
    assert.equal(populated.pagination.to, 3);
    assert.equal(populated.pagination.total, 3);
    assert.equal(populated.pagination.per_page, 15);
    assert.equal(populated.pagination.path, '/api/get-groups');
    assert.deepEqual(response.examples.empty.value, { data: null });
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('guards route, query, package, and focused PHP contract drift', async () => {
    const [routes, query, packageSource, compatibility] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Domain/InventoryFeatures/Queries/TypeMetadataQuery.php'),
        readText('package.json'),
        readText('tests/Feature/Legacy/TypeMetadataCompatibilityTest.php'),
    ]);
    const packageJson = JSON.parse(packageSource);

    assert.match(routes, /Route::get\('get-groups', \[TypeMetadataController::class, 'groups'\]\)/);
    const versionedRoute = routes.match(/Route::get\('inventory\/groups', \[TypeMetadataController::class, 'groups'\]\)[\s\S]*?->name\('inventory\.groups'\);/);
    assert.ok(versionedRoute);
    assert.doesNotMatch(versionedRoute[0], /middleware|auth/i);
    assert.match(query, /'default_types_groups'/);
    assert.match(query, /'default_types_groups_translations'/);
    assert.match(query, /\['slug'\]/);
    assert.match(query, /private const DATA_PAGE_SIZE = 100/);
    assert.match(query, /private const LEGACY_PAGINATION_SIZE = 15/);
    assert.match(query, /max\(1, \(int\) \$request->query\('page', 1\)\)/);
    assert.match(query, /is_string\(\$locale\) && \$locale !== '' \? \$locale : 'en'/);
    assert.match(query, /orderBy\("\$table\.sort_order"\)/);
    assert.match(query, /return \['data' => null\]/);
    assert.match(query, /'\/api\/get-groups'/);
    assert.match(query, /http_build_query\(\$query\)/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/inventory-groups-contract\.test\.mjs/);
    assert.match(compatibility, /test_versioned_groups_rows_preserve_exact_order_scalar_types_and_nullability/);
    assert.match(compatibility, /test_groups_use_deployment_locale_and_fall_back_to_en_for_empty_or_non_string_locale/);
    assert.match(compatibility, /test_groups_page_two_uses_a_hundred_row_offset_but_fifteen_row_legacy_metadata/);
    assert.match(compatibility, /test_groups_optional_global_rate_limit_preserves_legacy_error_envelope/);
});
