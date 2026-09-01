import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/users/profile']?.get;
const schemas = specification.components?.schemas ?? {};
const timestampSchema = {
    type: ['string', 'null'],
    format: 'date-time',
    pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
};
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

test('documents only the unauthenticated versioned public user profile operation', () => {
    assert.ok(operation, 'GET /api/v1/users/profile must be documented');
    assert.equal(specification.paths['/api/search-user'], undefined);
    assert.equal(operation.operationId, 'getPublicUserProfile');
    assert.deepEqual(operation.tags, ['Identity']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters, [
        {
            name: 'options[parameters][id]',
            in: 'query',
            required: false,
            description: 'The only supported user-selection value. The canonical wire form is a scalar query string evaluated by PHP `is_numeric`, integer-cast, and required to be positive after the cast. Decimal and exponent strings remain compatible; a top-level `id` is ignored.',
            schema: { type: 'string' },
            examples: {
                integerString: { value: '210' },
                fractionalString: { value: '210.9' },
                exponentString: { value: '2.1e2' },
            },
        },
        {
            name: 'page',
            in: 'query',
            required: false,
            description: 'Optional scalar pagination value. PHP integer-casts it and clamps it to at least 1; it changes only `current_page` and the generated page links.',
            schema: { type: 'string' },
            example: '1',
        },
    ]);
    assert.deepEqual(Object.keys(operation.responses), ['200', '404', '429']);
    assert.match(operation.description, /same controller and query as legacy `GET \/api\/search-user`/);
    assert.match(operation.description, /only user-selection input is the nested query value `options\[parameters\]\[id\]`/);
    assert.match(operation.description, /top-level `id` is ignored/);
    assert.match(operation.description, /PHP `is_numeric`, is integer-cast, and then be greater than zero/);
    assert.match(operation.description, /decimal and exponent forms/);
    assert.match(operation.description, /six-digit UTC ISO strings ending in `Z`/);
    assert.match(operation.description, /fixed legacy path `\/api\/search-user`/);
    assert.match(operation.description, /bearer header may be sent but is irrelevant and never produces endpoint-specific 401/);
    assert.match(operation.description, /no endpoint-specific throttle, cache, ETag, conditional-request handling, or 304 response/);
    assert.match(operation.description, /disabled by default.*observes and logs would-be rejections until enforcement is enabled/s);
    assert.match(operation.description, /Generic database or runtime failures are not stable endpoint responses/);
    assert.equal(operation.responses['401'], undefined);
    assert.equal(operation.responses['304'], undefined);
    assert.match(operation.responses['429'].description, /disabled by default, observe-only when enabled with enforcement off/);
});

test('locks exact public profile row, pagination, error, and synthetic examples', () => {
    assert.deepEqual(schemas.PublicUserProfileRow.required, [
        'id',
        'username',
        'user_profile_photo',
        'user_bio',
        'created_at',
        'updated_at',
        'km',
        'photos',
    ]);
    assert.deepEqual(Object.keys(schemas.PublicUserProfileRow.properties), schemas.PublicUserProfileRow.required);
    assert.deepEqual(schemas.PublicUserProfileRow.properties.id, {
        type: 'integer',
        format: 'int64',
        minimum: 1,
    });
    assert.deepEqual(schemas.PublicUserProfileRow.properties.username, { type: ['string', 'null'] });
    assert.deepEqual(schemas.PublicUserProfileRow.properties.user_profile_photo, { type: 'string' });
    assert.deepEqual(schemas.PublicUserProfileRow.properties.user_bio, { type: ['string', 'null'] });
    assert.deepEqual(schemas.PublicUserProfileRow.properties.created_at, timestampSchema);
    assert.deepEqual(schemas.PublicUserProfileRow.properties.updated_at, timestampSchema);
    assert.deepEqual(schemas.PublicUserProfileRow.properties.km, { type: 'string' });
    assert.deepEqual(schemas.PublicUserProfileRow.properties.photos, { type: 'integer', minimum: 0 });

    assert.deepEqual(schemas.PublicUserProfilePagination.required, [
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
    ]);
    assert.deepEqual(schemas.PublicUserProfilePagination.properties.links, {
        type: 'array',
        minItems: 3,
        maxItems: 3,
        items: { $ref: '#/components/schemas/PublicUserProfilePaginationLink' },
    });
    assert.deepEqual(schemas.PublicUserProfilePagination.properties.path, { const: '/api/search-user' });
    assert.deepEqual(schemas.PublicUserProfilePagination.properties.per_page, { const: 15 });
    assert.deepEqual(schemas.PublicUserProfilePagination.properties.next_page_url, { const: null });
    assert.deepEqual(schemas.PublicUserProfilePagination.properties.prev_page_url, { const: null });
    assert.deepEqual(schemas.PublicUserProfilePaginationLink, {
        type: 'object',
        additionalProperties: false,
        required: ['url', 'label', 'active'],
        properties: {
            url: { type: ['string', 'null'] },
            label: { type: 'string' },
            active: { type: 'boolean' },
        },
    });
    assert.deepEqual(schemas.PublicUserProfileSuccessResponse.properties.data, {
        type: 'array',
        minItems: 1,
        maxItems: 1,
        items: { $ref: '#/components/schemas/PublicUserProfileRow' },
    });
    assert.deepEqual(schemas.PublicUserProfileUnknownResponse.properties.data, { const: null });
    assert.deepEqual(schemas.PublicUserProfileNotFoundError.properties.message, { const: 'Not Found' });
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });

    const examples = operation.responses['200'].content['application/json'].examples;
    assert.deepEqual(examples.populated.value.data[0], {
        id: 210,
        username: 'synthetic-mapper',
        user_profile_photo: 'https://mapilio.test/default-avatar.png',
        user_bio: 'Mapping synthetic roads.',
        created_at: '2026-01-02T10:11:41.000000Z',
        updated_at: '2026-08-20T09:00:32.000000Z',
        km: '6.8',
        photos: 2,
    });
    assert.equal(examples.populated.value.pagination.path, '/api/search-user');
    assert.equal(examples.populated.value.pagination.per_page, 15);
    assert.deepEqual(examples.unknownOrDeleted.value, { data: null });
    assert.deepEqual(operation.responses['404'].content['application/json'].examples.topLevelOnly.value, {
        message: 'Not Found',
    });
    assert.doesNotMatch(JSON.stringify(operation), /access_token|refresh_token|secret|password/i);
});

test('guards route, controller, query, limiter, PHP coverage, package registration, and generated docs', async () => {
    const [routes, controller, query, config, throttle, bootstrap, compatibility, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Identity/PublicUserProfileController.php'),
        readText('app/Domain/IdentityAccess/Queries/PublicUserProfileQuery.php'),
        readText('config/mapilio.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('bootstrap/app.php'),
        readText('tests/Feature/Legacy/PublicUserProfileCompatibilityTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const packageJson = JSON.parse(packageSource);
    const routeStatement = routes.match(/Route::get\('users\/profile', PublicUserProfileController::class\)\s*->name\('users\.profile'\);/s)?.[0];
    const byIdMethod = query.match(/public function byId\(int \$userId, Request \$request\): array\s*\{[\s\S]*?\n    \}\n\n    private function capturedKilometers/)?.[0];

    assert.ok(routeStatement, 'The v1 profile route must remain a local GET statement.');
    assert.doesNotMatch(routeStatement, /middleware/);
    assert.match(routes, /Route::match\(\['GET', 'POST'\], 'search-user', PublicUserProfileController::class\)\s*->name\('api\.legacy\.search-user'\);/s);
    assert.match(controller, /data_get\(\$request->query\(\), 'options\.parameters\.id'\)/);
    assert.match(controller, /! is_numeric\(\$userId\) \|\| \(int\) \$userId <= 0/);
    assert.match(controller, /response\(\)->json\(\[\s*'message' => 'Not Found',\s*\], 404\)/s);
    assert.match(controller, /\$query->byId\(\(int\) \$userId, \$request\)/);
    assert.ok(byIdMethod, 'The query method block must remain locally inspectable.');
    assert.match(byIdMethod, /\$path = '\/api\/search-user'/);
    assert.match(byIdMethod, /->where\('id', \$userId\)\s*->whereNull\('deleted_at'\)/s);
    assert.match(byIdMethod, /'id',\s*'username',\s*'user_profile_photo',\s*'user_bio',\s*'created_at',\s*'updated_at'/s);
    assert.match(byIdMethod, /\$user->user_profile_photo\s*\?\: config\('mapilio\.mobile_auth\.default_profile_photo_url'\)/s);
    assert.match(query, /round\(\(float\) \$value, 1\)/);
    assert.match(query, /return \$this->numericString\(round\(\(float\) \$value, 1\)\)/);
    assert.match(query, /->where\('created_by_id', \$userId\)\s*->whereNull\('project_key'\)\s*->whereNull\('deleted_at'\)/s);
    assert.match(query, /->table\('default_mapilio_imagery'\)[\s\S]*?->count\(\)/);
    assert.match(query, /date\('Y-m-d\\TH:i:s\.000000\\Z', \$timestamp\)/);
    assert.match(query, /\$query\['page'\] = \$page/);
    assert.match(query, /return \$path\.'\?'\.http_build_query\(\$query\)/);
    assert.match(config, /'enabled' => env\('MAPILIO_API_RATE_LIMITING_ENABLED', false\)/);
    assert.match(config, /'enforce' => env\('MAPILIO_API_RATE_LIMITING_ENFORCE', false\)/);
    assert.match(config, /'max_attempts' => max\(1, \(int\) env\('MAPILIO_API_RATE_LIMIT_MAX_ATTEMPTS', 300\)\)/);
    assert.match(config, /'decay_seconds' => max\(1, \(int\) env\('MAPILIO_API_RATE_LIMIT_DECAY_SECONDS', 60\)\)/);
    assert.match(bootstrap, /\$middleware->api\(append: \[\s*ThrottleApiRequests::class,\s*\]\);/s);
    assert.match(throttle, /return response\(\)->json\(\[\s*'success' => false,\s*'message' => \['Too many requests\.'\],\s*'error_code' => Response::HTTP_TOO_MANY_REQUESTS,/s);
    assert.match(throttle, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(throttle, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(throttle, /'X-RateLimit-Remaining' => '0'/);

    for (const testName of [
        'test_versioned_public_user_profile_populated_response_freezes_exact_row_pagination_and_link_types',
        'test_versioned_public_user_profile_alias_matches_legacy_response_exactly',
        'test_versioned_public_user_profile_applies_nested_numeric_cast_and_ignores_top_level_id',
        'test_versioned_public_user_profile_rejects_missing_and_invalid_nested_ids_exactly',
        'test_versioned_public_user_profile_returns_null_for_unknown_and_soft_deleted_users',
        'test_versioned_public_user_profile_preserves_nullable_and_derived_fields',
        'test_versioned_public_user_profile_is_bearer_irrelevant',
        'test_versioned_public_user_profile_optional_global_rate_limit_preserves_exact_envelope_and_headers',
        'test_versioned_public_user_profile_ignores_conditional_headers_and_emits_no_etag',
    ]) {
        assert.match(compatibility, new RegExp(`function ${testName}`));
    }
    assert.match(packageJson.scripts['test:public-user-profile-contract'], /node --test scripts\/docs\/public-user-profile-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/public-user-profile-contract\.test\.mjs/);

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
