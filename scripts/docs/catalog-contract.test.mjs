import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/content/catalog']?.get;
const schemas = specification.components?.schemas ?? {};
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

test('documents only the unauthenticated v1 catalog alias', () => {
    assert.ok(operation, 'GET /api/v1/content/catalog must be documented');
    assert.equal(specification.paths['/api/catalog'], undefined);
    assert.equal(operation.operationId, 'getCatalog');
    assert.deepEqual(operation.tags, ['Public content']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), [
        { name: 'locale', in: 'query', required: false },
    ]);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.equal(operation.responses['401'], undefined);
    assert.equal(operation.responses['304'], undefined);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/catalog`/);
    assert.match(operation.description, /bearer token is irrelevant/);
    assert.match(operation.description, /empty or non-string value.*falls back to the literal `en`/);
    assert.match(operation.description, /omission uses the deployment application locale/);
    assert.match(operation.description, /ascending `catalog\.sort_order`.*ascending `catalog\.id`/);
    assert.match(operation.description, /data.*object keyed by catalog ID/);
    assert.match(operation.description, /arrays that begin with `null`/);
    assert.match(operation.description, /zero-image entry.*`\[null\]`/);
    assert.match(operation.description, /empty catalog.*`data: \[\]`/);
    assert.match(operation.description, /No endpoint-specific throttle, response cache, ETag/);
    assert.match(operation.responses['429'].description, /optional deployment-wide global limiter/);
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
    assert.doesNotMatch(JSON.stringify(operation), /mapilio\.com|real customer|production data/i);
});

test('locks the exact catalog entry, ID-keyed data, and empty response schemas', () => {
    assert.deepEqual(schemas.CatalogAssetUrls.minItems, 1);
    assert.deepEqual(schemas.CatalogAssetUrls.prefixItems, [{ type: 'null' }]);
    assert.deepEqual(schemas.CatalogAssetUrls.items, { type: 'string', format: 'uri' });
    assert.deepEqual(schemas.CatalogEntryProperties.required, ['name', 'year']);
    assert.deepEqual(Object.keys(schemas.CatalogEntryProperties.properties), ['name', 'year']);
    for (const field of ['name', 'year']) {
        assert.deepEqual(schemas.CatalogEntryProperties.properties[field], { type: ['string', 'null'] });
    }
    assert.deepEqual(schemas.CatalogEntry.required, ['properties', 'thumbnails', 'images']);
    assert.deepEqual(Object.keys(schemas.CatalogEntry.properties), ['properties', 'thumbnails', 'images']);
    assert.deepEqual(schemas.CatalogData, {
        type: 'object',
        minProperties: 1,
        propertyNames: { pattern: '^[0-9]+$' },
        additionalProperties: { $ref: '#/components/schemas/CatalogEntry' },
        description: 'Populated catalog data keyed by the numeric catalog ID serialized as a JSON object property name.',
    });
    assert.deepEqual(schemas.CatalogEmptyData, {
        type: 'array',
        maxItems: 0,
        items: false,
        description: 'Exact empty-catalog data shape: an empty JSON array.',
    });
    assert.deepEqual(schemas.CatalogResponse.required, ['status', 'data']);
    assert.deepEqual(Object.keys(schemas.CatalogResponse.properties), ['status', 'data']);
    assert.deepEqual(schemas.CatalogResponse.properties.status, { const: true });
    assert.deepEqual(schemas.CatalogResponse.properties.data.oneOf, [
        { $ref: '#/components/schemas/CatalogData' },
        { $ref: '#/components/schemas/CatalogEmptyData' },
    ]);
});

test('keeps synthetic populated, zero-image, empty, and rate-limit examples exact', () => {
    const response = operation.responses['200'].content['application/json'];
    const populated = response.examples.populated.value;

    assert.deepEqual(Object.keys(response.examples), ['populated', 'emptyCatalog']);
    assert.deepEqual(Object.keys(populated), ['status', 'data']);
    assert.deepEqual(Object.keys(populated.data), ['201', '202']);
    assert.deepEqual(Object.keys(populated.data['201']), ['properties', 'thumbnails', 'images']);
    assert.deepEqual(Object.keys(populated.data['201'].properties), ['name', 'year']);
    assert.equal(populated.data['201'].properties.name, 'Synthetic Catalog');
    assert.equal(populated.data['201'].properties.year, '2026');
    assert.deepEqual(populated.data['201'].thumbnails, [
        null,
        'https://catalog.example.test',
        'https://catalog.example.test',
    ]);
    assert.deepEqual(populated.data['201'].images, populated.data['201'].thumbnails);
    assert.deepEqual(populated.data['202'], {
        properties: { name: null, year: null },
        thumbnails: [null],
        images: [null],
    });
    assert.deepEqual(response.examples.emptyCatalog.value, {
        status: true,
        data: [],
    });
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
    assert.doesNotMatch(JSON.stringify(operation), /mapilio\.com|real customer|production data/i);
});

test('guards route, controller, query, PHP coverage, limiter, package registration, and generated docs', async () => {
    const [routes, controller, query, bootstrap, limiter, compatibility, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/PublicContent/CatalogController.php'),
        readText('app/Domain/PublicContent/Queries/CatalogQuery.php'),
        readText('bootstrap/app.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('tests/Feature/Legacy/PublicContentCompatibilityTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const legacyRoute = routes.match(/Route::get\('catalog', CatalogController::class\)\s*->name\('api\.legacy\.catalog'\);/);
    const versionedRoute = routes.match(/Route::get\('content\/catalog', CatalogController::class\)\s*->name\('content\.catalog'\);/);

    assert.ok(legacyRoute, 'The legacy catalog route must remain a local GET statement.');
    assert.ok(versionedRoute, 'The v1 catalog route must remain a local GET statement.');
    for (const route of [legacyRoute[0], versionedRoute[0]]) {
        assert.doesNotMatch(route, /middleware|throttle|auth/i);
    }
    assert.match(controller, /return response\(\)->json\(\$query->get\(\$request\)\);/);
    assert.match(query, /'default_catalog_catalog as catalog'/);
    assert.match(query, /'default_catalog_catalog_translations as translations'/);
    assert.match(query, /'default_catalog_catalog_catalog_images'/);
    assert.match(query, /getSchemeAndHttpHost\(\)/);
    assert.match(query, /where\('translations\.locale', '=', \$locale\)/);
    assert.match(query, /orderBy\('catalog\.sort_order'\)/);
    assert.match(query, /orderBy\('catalog\.id'\)/);
    assert.match(query, /array_merge\(\[null\], array_fill\(0, \$fileCount, \$assetRoot\)\)/);
    assert.match(query, /is_string\(\$locale\) && \$locale !== '' \? \$locale : 'en'/);
    assert.match(query, /'status' => true/);
    assert.match(bootstrap, /ThrottleApiRequests::class/);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /if \(\$enforce\) \{\s*return \$this->tooManyRequests/s);
    assert.match(limiter, /'success' => false/);
    assert.match(limiter, /'message' => \['Too many requests\.'\]/);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    for (const testName of [
        'test_legacy_catalog_preserves_entry_id_keys_and_root_image_urls',
        'test_versioned_catalog_alias_returns_same_contract',
        'test_catalog_uses_requested_locale_and_falls_back_to_en_for_empty_or_non_string_locale',
        'test_catalog_zero_image_rows_preserve_leading_null_arrays_and_sort_order',
        'test_empty_catalog_preserves_status_and_empty_data_array',
        'test_versioned_catalog_routes_only_use_shared_api_group_middleware',
    ]) {
        assert.match(compatibility, new RegExp(`function ${testName}\\(`));
    }

    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['test:catalog-contract'], /node --test scripts\/docs\/catalog-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/catalog-contract\.test\.mjs/);

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
