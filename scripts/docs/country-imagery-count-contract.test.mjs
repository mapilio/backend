import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/imagery/country-image-count']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = ['name', 'lon', 'lat', 'iso3', 'image_count'];
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
        description: 'Requests remaining in the current limiter window.',
        schema: { const: '0' },
    },
};

test('documents only the unauthenticated versioned country imagery-count operation', () => {
    assert.ok(operation, 'GET /api/v1/imagery/country-image-count must be documented');
    assert.equal(specification.paths['/api/country-image-count'], undefined);
    assert.equal(specification.paths['/api/get-image-count-by-country'], undefined);
    assert.equal(operation.operationId, 'getCountryImageCounts');
    assert.deepEqual(operation.tags, ['Imagery reads']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters, []);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.equal(operation.responses['401'], undefined);
    assert.equal(operation.responses['304'], undefined);
    assert.match(operation.description, /additive versioned alias of legacy `GET \/api\/country-image-count`/);
    assert.match(operation.description, /maintained web globe uses the legacy alias/);
    assert.match(operation.description, /same controller, query, and public aggregate cache/);
    assert.match(operation.description, /zero-input, unauthenticated GET/);
    assert.match(operation.description, /bearer token is irrelevant/);
    assert.match(operation.description, /unknown query parameters do not affect the result/);
    assert.match(operation.description, /no pagination or filtering/);
    assert.match(operation.description, /query applies no filtering or ordering/);
    assert.match(operation.description, /row ordering is unspecified/);
    assert.match(operation.description, /Generic database, cache, or runtime exceptions on a cold miss are not stable/);
    assert.match(operation.description, /mapilio:public:v1:imagery:country-image-count/);
    assert.match(operation.description, /defaults 60 seconds, 300 seconds, and 10 seconds/);
    assert.match(operation.description, /stale value is served while a deferred refresh runs/);
    assert.match(operation.description, /deferred refresh fails, the stale value is preserved/);
    assert.match(operation.description, /cold-miss failure bubbles/);
    assert.match(operation.description, /no edge or CDN caching promise/);
    assert.match(operation.description, /no endpoint-specific throttle/);
    assert.match(operation.description, /disabled and observe-only by default/);
    assert.match(operation.description, /exact 429 response/);
    assert.match(operation.description, /If-None-Match` is ignored/);
    assert.match(operation.description, /no ETag and does not return 304/);
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('locks the exact country imagery-count envelope, row field order, casts, and synthetic examples', () => {
    assert.deepEqual(schemas.CountryImageCountResponse, {
        type: 'object',
        additionalProperties: false,
        required: ['data'],
        properties: {
            data: {
                type: 'array',
                items: { $ref: '#/components/schemas/CountryImageCountRow' },
            },
        },
    });
    assert.deepEqual(schemas.CountryImageCountRow, {
        type: 'object',
        additionalProperties: false,
        required: rowFields,
        properties: {
            name: { type: 'string' },
            lon: { type: 'string' },
            lat: { type: 'string' },
            iso3: { type: 'string' },
            image_count: { type: 'integer' },
        },
    });

    const response = operation.responses['200'].content['application/json'];
    assert.deepEqual(response.schema, { $ref: '#/components/schemas/CountryImageCountResponse' });
    assert.deepEqual(Object.keys(response.examples), ['populated', 'empty']);
    const populated = response.examples.populated.value;
    assert.deepEqual(Object.keys(populated), ['data']);
    assert.deepEqual(populated.data.map((row) => Object.keys(row)), [rowFields, rowFields]);
    assert.equal(populated.data[0].name, 'Synthetic Coast');
    assert.equal(typeof populated.data[0].lon, 'string');
    assert.equal(typeof populated.data[0].lat, 'string');
    assert.equal(typeof populated.data[0].iso3, 'string');
    assert.equal(typeof populated.data[0].image_count, 'number');
    assert.deepEqual(populated.data[1], { name: '', lon: '', lat: '', iso3: '', image_count: 0 });
    assert.deepEqual(response.examples.empty.value, { data: [] });
    assert.doesNotMatch(JSON.stringify(operation), /real country|Algeria|Armenia|Turkey|United States/i);
});

test('guards route, controller, query, cache, limiter, PHP coverage, package registration, and generated docs', async () => {
    const [routes, controller, query, cache, cacheConfig, limiter, bootstrap, compatibility, cacheTest, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Imagery/CountryImageCountController.php'),
        readText('app/Domain/ImagerySequences/Queries/CountryImageCountQuery.php'),
        readText('app/Support/Cache/PublicAggregateCache.php'),
        readText('config/mapilio.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('bootstrap/app.php'),
        readText('tests/Feature/Legacy/CountryImageCountCompatibilityTest.php'),
        readText('tests/Feature/PublicAggregateCacheTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const legacyRoute = routes.match(/Route::get\('country-image-count', CountryImageCountController::class\)\s*->name\('api\.legacy\.country-image-count'\);/)?.[0];
    const versionedRoute = routes.match(/Route::get\('imagery\/country-image-count', CountryImageCountController::class\)\s*->name\('imagery\.country-image-count'\);/)?.[0];

    assert.ok(legacyRoute, 'The legacy country-count route must remain a local GET statement.');
    assert.ok(versionedRoute, 'The v1 country-count route must remain a local GET statement.');
    for (const route of [legacyRoute, versionedRoute]) {
        assert.doesNotMatch(route, /middleware|throttle/i);
    }
    assert.doesNotMatch(routes, /get-image-count-by-country/);

    assert.match(controller, /return response\(\)->json\(\[\s*'data' => \$cache->countryImageCounts\(fn \(\): array => \$query->get\(\)->values\(\)->all\(\)\),\s*\]\);/s);
    assert.match(query, /->table\('country_image_count'\)\s*->select\(\['name', 'lon', 'lat', 'iso3', 'image_count'\]\)\s*->get\(\)/s);
    assert.match(query, /'name' => \(string\) \$row->name/);
    assert.match(query, /'lon' => \(string\) \$row->lon/);
    assert.match(query, /'lat' => \(string\) \$row->lat/);
    assert.match(query, /'iso3' => \(string\) \$row->iso3/);
    assert.match(query, /'image_count' => \(int\) \$row->image_count/);
    assert.doesNotMatch(query, /->where\(|->orderBy\(|->paginate\(/);

    assert.match(cache, /public const COUNTRY_IMAGE_COUNT_KEY = 'mapilio:public:v1:imagery:country-image-count';/);
    assert.match(cache, /return \$this->remember\(self::COUNTRY_IMAGE_COUNT_KEY, \$callback\);/);
    assert.match(cache, /Cache::flexible\(/);
    assert.match(cache, /config\('mapilio\.public_aggregate_cache\.fresh_seconds', 60\)/);
    assert.match(cache, /config\('mapilio\.public_aggregate_cache\.stale_through_seconds', 300\)/);
    assert.match(cache, /config\('mapilio\.public_aggregate_cache\.refresh_lock_seconds', 10\)/);
    assert.match(cacheConfig, /'enabled' => env\('MAPILIO_PUBLIC_AGGREGATE_CACHE_ENABLED', true\)/);
    assert.match(cacheConfig, /'fresh_seconds' => max\(1, min\(300, \(int\) env\('MAPILIO_PUBLIC_AGGREGATE_CACHE_FRESH_SECONDS', 60\)\)\)/);
    assert.match(cacheConfig, /'stale_through_seconds'/);
    assert.match(cacheConfig, /'refresh_lock_seconds'/);

    assert.match(bootstrap, /\$middleware->api\(append: \[\s*ThrottleApiRequests::class,/s);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enforce', false\)/);
    assert.match(limiter, /'success' => false/);
    assert.match(limiter, /'message' => \['Too many requests\.'\]/);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    assert.match(limiter, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(limiter, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(limiter, /'X-RateLimit-Remaining' => '0'/);
    assert.doesNotMatch(limiter, /X-RateLimit-Reset/);

    for (const testName of [
        'test_legacy_country_image_count_path_preserves_response_shape',
        'test_versioned_country_image_count_alias_returns_same_contract',
        'test_country_image_count_empty_result_is_exactly_an_empty_data_array',
        'test_country_image_count_casts_null_columns_to_empty_strings_and_zero',
        'test_versioned_country_image_count_is_bearer_and_unknown_query_irrelevant',
        'test_versioned_country_image_count_optional_global_rate_limit_preserves_exact_envelope_and_headers',
        'test_versioned_country_image_count_ignores_conditional_headers_and_emits_no_etag',
    ]) {
        assert.match(compatibility, new RegExp(`function ${testName}`));
    }
    assert.match(cacheTest, /test_country_counts_aliases_share_one_computation_and_exact_wrapper/);
    assert.match(cacheTest, /test_failed_deferred_refresh_keeps_the_stale_value/);
    assert.match(cacheTest, /COUNTRY_IMAGE_COUNT_KEY/);

    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['test:country-imagery-count-contract'], /node --test scripts\/docs\/country-imagery-count-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/country-imagery-count-contract\.test\.mjs/);

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
