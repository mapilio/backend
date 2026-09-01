import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/billing/packages']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = [
    'id',
    'sort_order',
    'created_at',
    'created_by_id',
    'updated_at',
    'updated_by_id',
    'deleted_at',
    'km_price',
    'currency',
    'interval_period',
    'image_id',
    'hover_image_id',
    'image_url',
    'hover_image_url',
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
        description: 'Requests remaining in the current limiter window; the rejected response serializes this as `0`.',
        schema: { const: '0' },
    },
};

test('documents only the unauthenticated v1 billing package alias', () => {
    assert.ok(operation, 'GET /api/v1/billing/packages must be documented');
    assert.equal(specification.paths['/api/package-list'], undefined);
    assert.equal(operation.operationId, 'getBillingPackages');
    assert.deepEqual(operation.tags, ['Billing']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), [
        { name: 'page', in: 'query', required: false },
        { name: 'locale', in: 'query', required: false },
    ]);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.equal(operation.responses['401'], undefined);
    assert.equal(operation.responses['304'], undefined);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/package-list`/);
    assert.match(operation.description, /maintained pricing UI/);
    assert.match(operation.description, /bearer token is irrelevant/);
    assert.match(operation.description, /PHP-int-cast and minimum-clamped to 1/);
    assert.match(operation.description, /empty or non-string locale falls back to `en`/);
    assert.match(operation.description, /deployment application locale/);
    assert.match(operation.description, /limit and offset of 100/);
    assert.match(operation.description, /`per_page` 15/);
    assert.match(operation.description, /fixed legacy `\/api\/package-list` URLs/);
    assert.match(operation.description, /preserve parsed query parameters/);
    assert.match(operation.description, /enumerate every numeric page/);
    assert.match(operation.description, /No portable response ordering is promised/);
    assert.match(operation.description, /rowid.*SQLite/);
    assert.match(operation.description, /empty or out-of-range page.*\{data: null\}.*no pagination/);
    assert.match(operation.description, /no endpoint-specific throttle/);
    assert.match(operation.description, /response cache, ETag, 304, or queue behavior/);
    assert.match(operation.description, /Generic database failures are not stabilized/);
    assert.match(operation.responses['429'].description, /deployment-wide global limiter/);
});

test('locks the exact billing package row, pagination, and response schemas', () => {
    assert.deepEqual(schemas.BillingPackageRow.required, rowFields);
    assert.deepEqual(Object.keys(schemas.BillingPackageRow.properties), rowFields);
    assert.deepEqual(schemas.BillingPackageRow.properties.id, { type: 'integer', format: 'int64' });
    for (const field of ['sort_order', 'created_by_id', 'updated_by_id', 'image_id', 'hover_image_id']) {
        assert.deepEqual(schemas.BillingPackageRow.properties[field], { type: ['integer', 'null'] });
    }
    for (const field of ['created_at', 'updated_at']) {
        assert.deepEqual(schemas.BillingPackageRow.properties[field], {
            type: ['string', 'null'],
            format: 'date-time',
            pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
        });
    }
    assert.deepEqual(schemas.BillingPackageRow.properties.deleted_at, { type: 'null' });
    assert.deepEqual(schemas.BillingPackageRow.properties.km_price, {
        type: ['string', 'null'],
        description: 'Stored numeric kilometer price serialized as a decimal string when present.',
    });
    for (const field of ['currency', 'interval_period', 'name']) {
        assert.deepEqual(schemas.BillingPackageRow.properties[field], { type: ['string', 'null'] });
    }
    for (const field of ['image_url', 'hover_image_url']) {
        assert.deepEqual(schemas.BillingPackageRow.properties[field], { type: ['string', 'null'], format: 'uri' });
    }

    assert.deepEqual(schemas.BillingPackagesPagination.required, paginationFields);
    assert.deepEqual(Object.keys(schemas.BillingPackagesPagination.properties), paginationFields);
    assert.deepEqual(schemas.BillingPackagesPagination.properties.links.items, {
        $ref: '#/components/schemas/BillingPackagePaginationLink',
    });
    assert.deepEqual(schemas.BillingPackagesPagination.properties.path, {
        type: 'string',
        const: '/api/package-list',
    });
    assert.deepEqual(schemas.BillingPackagesPagination.properties.per_page, { type: 'integer', const: 15 });
    assert.deepEqual(schemas.BillingPackagesResponse.required, ['data']);
    assert.equal(schemas.BillingPackagesResponse.oneOf.length, 2);
    assert.deepEqual(schemas.BillingPackagesResponse.oneOf[0].required, ['data', 'pagination']);
    assert.equal(schemas.BillingPackagesResponse.oneOf[1].maxProperties, 1);
    assert.deepEqual(Object.keys(schemas.BillingPackagesResponse.properties), ['data', 'pagination']);
    assert.equal(schemas.BillingPackagesResponse.properties.data.oneOf[0].minItems, 1);
});

test('keeps synthetic populated, empty, and optional rate-limit examples exact', () => {
    const response = operation.responses['200'].content['application/json'];
    const populated = response.examples.populated.value;

    assert.deepEqual(Object.keys(response.examples), ['populated', 'empty']);
    assert.deepEqual(Object.keys(populated), ['data', 'pagination']);
    assert.deepEqual(populated.data.map((row) => Object.keys(row)), [rowFields, rowFields]);
    assert.deepEqual(populated.data.map(({ id, km_price, image_id }) => ({ id, km_price, image_id })), [
        { id: 101, km_price: '12.5', image_id: 901 },
        { id: 102, km_price: null, image_id: null },
    ]);
    assert.equal(typeof populated.data[0].id, 'number');
    assert.equal(typeof populated.data[0].km_price, 'string');
    assert.equal(typeof populated.data[0].image_url, 'string');
    assert.equal(populated.data[1].name, null);
    assert.equal(populated.data[1].created_at, null);
    assert.equal(populated.data[1].hover_image_url, null);
    assert.equal(populated.pagination.per_page, 15);
    assert.equal(populated.pagination.path, '/api/package-list');
    assert.deepEqual(response.examples.empty.value, { data: null });
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
    assert.doesNotMatch(JSON.stringify(operation), /mapilio\.com|real customer|production plan/i);
});

test('guards repository-local source evidence, PHP coverage, package registration, and generated docs', async () => {
    const [routes, controller, query, bootstrap, limiter, compatibility, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Billing/BillingPlanController.php'),
        readText('app/Domain/BillingCatalog/Queries/BillingPlanQuery.php'),
        readText('bootstrap/app.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('tests/Feature/Legacy/BillingPlanCompatibilityTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const legacyRoute = routes.match(/Route::get\('package-list', \[BillingPlanController::class, 'packages'\]\)\s*->name\('api\.legacy\.billing\.packages'\);/);
    const versionedRoute = routes.match(/Route::get\('billing\/packages', \[BillingPlanController::class, 'packages'\]\)\s*->name\('billing\.packages'\);/);

    assert.ok(legacyRoute, 'The legacy package route must remain a local GET statement.');
    assert.ok(versionedRoute, 'The v1 package route must remain a local GET statement.');
    for (const route of [legacyRoute[0], versionedRoute[0]]) {
        assert.doesNotMatch(route, /middleware|throttle|auth/i);
    }
    assert.match(controller, /return response\(\)->json\(\$query->packages\(\$request\)\);/);
    assert.match(query, /'default_billing_package'/);
    assert.match(query, /'default_billing_package_translations'/);
    assert.match(query, /'\/api\/package-list'/);
    assert.match(query, /private const DATA_PAGE_SIZE = 100/);
    assert.match(query, /private const LEGACY_PAGINATION_SIZE = 15/);
    assert.match(query, /max\(1, \(int\) \$request->query\('page', 1\)\)/);
    assert.match(query, /is_string\(\$locale\) && \$locale !== '' \? \$locale : 'en'/);
    assert.match(query, /->limit\(self::DATA_PAGE_SIZE\)/);
    assert.match(query, /->offset\(\(\$page - 1\) \* self::DATA_PAGE_SIZE\)/);
    assert.match(query, /orderBy\('base\.rowid'\)/);
    assert.match(query, /getSchemeAndHttpHost\(\)/);
    assert.match(query, /'km_price' => \$this->numericString\(\$row->km_price\)/);
    assert.match(query, /'image_url' => \$row->image_id === null \? null : \$assetRoot/);
    assert.match(query, /return \['data' => null\]/);
    assert.match(query, /http_build_query\(\$query\)/);
    assert.match(bootstrap, /ThrottleApiRequests::class/);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /if \(\$enforce\) \{\s*return \$this->tooManyRequests/s);
    assert.match(limiter, /'success' => false/);
    assert.match(limiter, /'message' => \['Too many requests\.'\]/);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    for (const testName of [
        'test_versioned_packages_rows_preserve_exact_order_scalar_types_and_nullability',
        'test_packages_use_deployment_locale_and_fall_back_to_en_for_empty_or_non_string_locale',
        'test_packages_scalar_page_is_php_cast_and_minimum_clamped',
        'test_packages_page_two_uses_a_hundred_row_offset_but_fifteen_row_legacy_metadata',
        'test_versioned_packages_use_request_scheme_and_host_for_image_urls',
        'test_versioned_packages_are_bearer_irrelevant_and_preserve_conditional_header_behavior',
        'test_versioned_packages_optional_global_rate_limit_preserves_exact_envelope_and_headers',
        'test_empty_billing_pages_return_data_null',
        'test_versioned_billing_aliases_return_same_contract',
    ]) {
        assert.match(compatibility, new RegExp(`function ${testName}\\(`));
    }

    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['test:billing-packages-contract'], /node --test scripts\/docs\/billing-packages-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/billing-packages-contract\.test\.mjs/);

    const specificationStart = generatedHtml.indexOf('    const specification = ');
    const specificationEnd = generatedHtml.indexOf(';\n    const options = ', specificationStart);
    assert.ok(specificationStart >= 0 && specificationEnd > specificationStart, 'Generated HTML must embed the OpenAPI specification.');
    const embeddedSpecification = JSON.parse(generatedHtml.slice(
        specificationStart + '    const specification = '.length,
        specificationEnd,
    ));
    assert.deepEqual(embeddedSpecification, specification);
    assert.doesNotMatch(generatedHtml, /(?:\.\.\/|sibling mobile|mapilio-mobile)/i);
});
