import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readJson = async (relativePath) => JSON.parse(await readFile(resolve(repositoryRoot, relativePath), 'utf8'));
const readText = async (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');

const specification = await readJson('docs/api/openapi-v1.json');
const operation = specification.paths?.['/api/v1/imagery/user-upload-details']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = ['filename', 'last_status', 'sequence_uuid', 'id', 'img_code', 'latitude', 'longitude', 'heading', 'created_by_id', 'created_at', 'capture_time'];
const paginationFields = ['current_page', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'];
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

test('documents the unauthenticated legacy-compatible detail request contract', () => {
    assert.ok(operation, 'GET /api/v1/imagery/user-upload-details must be documented');
    assert.equal(operation.operationId, 'getUserUploadDetails');
    assert.deepEqual(operation.tags, ['Imagery reads']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), [
        { name: 'options[parameters][user_id]', in: 'query', required: true },
        { name: 'options[parameters][group_key]', in: 'query', required: true },
        { name: 'options[limit]', in: 'query', required: false },
        { name: 'page', in: 'query', required: false },
    ]);
    assert.deepEqual(operation.parameters.map(({ schema }) => schema), [
        { type: ['number', 'string'], minimum: 1 },
        { type: ['string', 'number', 'boolean'] },
        { type: ['number', 'string'], default: 15 },
        { type: ['number', 'string'], default: 1 },
    ]);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/user-uploads-detail-v2`/);
    assert.match(operation.description, /PHP `is_numeric`/);
    assert.match(operation.description, /integer-cast with decimals truncated/);
    assert.match(operation.description, /greater than zero/);
    assert.match(operation.description, /rejects only null and the empty string/);
    assert.match(operation.description, /no maximum/);
    assert.match(operation.description, /Arrays, overflow, generic database failures, and invalid stored timestamps are outside this contract/);
    assert.match(operation.description, /ordered ascending by `imagery\.id`/);
    assert.match(operation.description, /no image URL, geometry, GeoJSON, organization, or project fields/);
    assert.match(operation.description, /re-encoding Laravel's parsed query keys with PHP `http_build_query` after overwriting `page`/);
    assert.match(operation.description, /nested `options` keys are serialized together before later top-level keys/);
    assert.match(operation.description, /Previous, a legacy bounded numeric\/ellipsis window, and Next/);
    assert.match(operation.description, /no endpoint authentication, endpoint-specific response cache, or ETag/);
    assert.match(operation.description, /Runtime bounds and performance, database indexes, duplicate joins, privacy\/auth policy, clients and migration, and external services are outside this contract/);
    assert.deepEqual(Object.keys(operation.responses), ['200', '400', '429']);
    assert.match(operation.responses['429'].description, /Enforcement and limits are deployment-configurable/);
    assert.match(operation.responses['429'].description, /production enforcement is not asserted/);
});

test('freezes the exact detail row and populated pagination schemas', () => {
    const row = schemas.UserUploadDetailsRow;
    assert.ok(row);
    assert.deepEqual(row.required, rowFields);
    assert.deepEqual(Object.keys(row.properties), rowFields);
    assert.equal(row.additionalProperties, false);
    assert.deepEqual(row.properties.filename, { type: ['string', 'null'] });
    assert.deepEqual(row.properties.last_status, { type: ['string', 'null'] });
    assert.deepEqual(row.properties.sequence_uuid, { type: ['string', 'null'] });
    assert.deepEqual(row.properties.id, { type: 'integer', format: 'int64' });
    assert.deepEqual(row.properties.img_code, { type: ['string', 'null'] });
    assert.deepEqual(row.properties.latitude, { type: ['string', 'null'] });
    assert.deepEqual(row.properties.longitude, { type: ['string', 'null'] });
    assert.deepEqual(row.properties.heading, { type: ['number', 'null'] });
    assert.deepEqual(row.properties.created_by_id, { type: ['integer', 'null'], format: 'int64' });
    assert.deepEqual(row.properties.created_at, {
        type: ['string', 'null'],
        format: 'date-time',
        pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
    });
    assert.deepEqual(row.properties.capture_time, {
        type: ['string', 'null'],
        pattern: '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
    });

    const pagination = schemas.UserUploadDetailsPagination;
    assert.ok(pagination);
    assert.equal(pagination.additionalProperties, false);
    assert.deepEqual(pagination.required, paginationFields);
    assert.equal(pagination.properties.links.items.$ref, '#/components/schemas/UserUploadDetailsPaginationLink');
    assert.deepEqual(pagination.properties.path, { type: 'string', const: '/api/user-uploads-detail-v2' });
    assert.deepEqual(pagination.properties.per_page, { type: 'integer', minimum: 1 });
    assert.equal(Object.hasOwn(pagination.properties.per_page, 'maximum'), false);
    assert.deepEqual(schemas.UserUploadDetailsPaginationLink.required, ['url', 'label', 'active']);
});

test('models populated versus exact data-null responses and legacy examples', () => {
    const response = operation.responses['200'].content['application/json'];
    assert.deepEqual(Object.keys(response.examples), ['pageWithRows', 'empty', 'outOfRange']);
    assert.deepEqual(response.schema, { $ref: '#/components/schemas/UserUploadDetailsResponse' });

    const populated = response.examples.pageWithRows.value;
    assert.deepEqual(Object.keys(populated.data[0]), rowFields);
    assert.deepEqual(Object.keys(populated.data[1]), rowFields);
    assert.match(populated.data[0].created_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
    assert.match(populated.data[0].capture_time, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    assert.equal(populated.data[1].filename, null);
    assert.equal(populated.data[1].heading, null);
    assert.equal(populated.data[1].created_at, null);
    assert.equal(populated.data[1].capture_time, null);
    assert.equal(populated.pagination.path, '/api/user-uploads-detail-v2');
    assert.equal(populated.pagination.per_page, 2);
    assert.deepEqual(populated.pagination.links, [
        { url: null, label: '&laquo; Previous', active: false },
        { url: '/api/user-uploads-detail-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Bparameters%5D%5Bgroup_key%5D=group-new&options%5Blimit%5D=2&options%5Bextra%5D%5Bflag%5D=x&ignored=hello+world&page=1', label: '1', active: true },
        { url: null, label: 'Next &raquo;', active: false },
    ]);

    for (const name of ['empty', 'outOfRange']) {
        assert.deepEqual(response.examples[name].value, { data: null }, `${name} must be exactly {data:null}`);
    }

    const responseSchema = schemas.UserUploadDetailsResponse;
    assert.deepEqual(responseSchema.oneOf.map((variant) => variant.required), [['data', 'pagination'], ['data']]);
    assert.equal(responseSchema.oneOf[0].properties.data.minItems, 1);
    assert.deepEqual(responseSchema.oneOf[1].properties.data, { type: 'null' });
});

test('freezes both required-parameter 400 examples and the optional global 429', () => {
    const response = operation.responses['400'].content['application/json'];
    assert.deepEqual(Object.keys(response.examples), ['missingUserId', 'missingGroupKey']);
    assert.deepEqual(response.examples.missingUserId.value, {
        success: false,
        message: ["'user_id' is required!"],
        error_code: 400,
    });
    assert.deepEqual(response.examples.missingGroupKey.value, {
        success: false,
        message: ["'group_key' is required!"],
        error_code: 400,
    });
    assert.deepEqual(schemas.UserUploadDetailsValidationError.properties.error_code, { const: 400 });
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'], {
        schema: { $ref: '#/components/schemas/PublicRateLimitError' },
        example: { success: false, message: ['Too many requests.'], error_code: 429 },
    });
});

test('guards route, controller, query, fixture, and package drift', async () => {
    const [routes, controller, query, fixture, packageSource] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Imagery/UserUploadDetailsController.php'),
        readText('app/Domain/ImagerySequences/Queries/UserUploadDetailsQuery.php'),
        readText('tests/Feature/Legacy/UserUploadDetailsCompatibilityTest.php'),
        readText('package.json'),
    ]);
    const packageJson = JSON.parse(packageSource);

    const legacyRoute = routes.match(/Route::get\('user-uploads-detail-v2', UserUploadDetailsController::class\)[\s\S]*?->name\('api\.legacy\.user-uploads-detail-v2'\);/);
    const versionedRoute = routes.match(/Route::get\('imagery\/user-upload-details', UserUploadDetailsController::class\)[\s\S]*?->name\('imagery\.user-upload-details'\);/);
    assert.ok(legacyRoute);
    assert.ok(versionedRoute);
    assert.doesNotMatch(legacyRoute[0], /->middleware/);
    assert.doesNotMatch(versionedRoute[0], /->middleware/);
    assert.match(controller, /data_get\(\$request->query\(\), 'options\.parameters\.user_id'\)/);
    assert.match(controller, /data_get\(\$request->query\(\), 'options\.parameters\.group_key'\)/);
    assert.match(controller, /! is_numeric\(\$userId\) \|\| \(int\) \$userId <= 0/);
    assert.match(controller, /\$groupKey === null \|\| \$groupKey === ''/);
    assert.match(controller, /\(int\) \$userId, \(string\) \$groupKey/);
    assert.match(query, /data_get\(\$request->query\(\), 'options\.limit', 15\)/);
    assert.match(query, /max\(1, \(int\) \$request->query\('page', 1\)\)/);
    for (const field of [
        'imagery.filename', 'detail.last_status', 'imagery.sequence_uuid', 'imagery.id',
        'imagery.uploaded_hash as img_code', 'imagery.latitude', 'imagery.longitude',
        'imagery.heading', 'imagery.created_by_id', 'imagery.created_at', 'imagery.capture_time',
    ]) assert.match(query, new RegExp(`['"]${field.replace(/[.]/g, '\\.')}`));
    assert.match(query, /->orderBy\('imagery\.id'\)/);
    assert.match(query, /return \['data' => null\]/);
    assert.match(query, /\/api\/user-uploads-detail-v2/);
    assert.match(query, /\$query\['page'\] = \$page/);
    assert.match(query, /http_build_query\(\$query\)/);
    assert.match(fixture, /#\[DataProvider\('supportedScalarCoercionAndDefaultProvider'\)\]/);
    assert.match(fixture, /#\[DataProvider\('invalidRequiredScalarProvider'\)\]/);
    assert.match(fixture, /#\[DataProvider\('nonEmptyGroupKeyProvider'\)\]/);
    assert.match(fixture, /test_populated_response_has_exact_row_keys_scalars_nulls_timestamps_and_pagination/);
    assert.match(fixture, /\/api\/v1\/imagery\/user-upload-details/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/user-upload-details-contract\.test\.mjs/);
});
