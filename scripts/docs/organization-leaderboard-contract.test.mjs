import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/organizations/leaderboard']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = ['organization_key', 'organization_name', 'point', 'total_length', 'total_images'];

test('documents only the unauthenticated v1 organization leaderboard alias', () => {
    assert.ok(operation, 'GET /api/v1/organizations/leaderboard must be documented');
    assert.equal(operation.operationId, 'getOrganizationLeaderboard');
    assert.deepEqual(operation.tags, ['Public content']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters, []);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.match(operation.description, /additive versioned alias of `GET \/api\/leaderboard-organization`/);
    assert.match(operation.description, /no query or path parameters/);
    assert.match(operation.description, /date, limit, pagination.*ignored/);
    assert.match(operation.description, /sequence-point scoring version 1/);
    assert.match(operation.description, /does not expose `score_version`/);
    assert.doesNotMatch(operation.description, /OrganizationLeaderboardQuery|SCORE_VERSION_SEQUENCE|SQL/);
    assert.match(operation.description, /short-lived aggregate cache/);
    assert.match(operation.description, /ties are unspecified/);
    assert.match(operation.description, /no pagination metadata/);
    assert.doesNotMatch(operation.description, /invalid score_version/);
    assert.equal(operation.responses['400'], undefined);
    assert.deepEqual(Object.keys(operation.responses['429'].headers), [
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ]);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('locks the exact organization leaderboard envelope and row scalar mapping', () => {
    assert.deepEqual(schemas.OrganizationLeaderboardResponse.required, ['data']);
    assert.deepEqual(Object.keys(schemas.OrganizationLeaderboardResponse.properties), ['data']);
    assert.deepEqual(schemas.OrganizationLeaderboardResponse.properties.data.required, ['leaderboard']);
    assert.deepEqual(Object.keys(schemas.OrganizationLeaderboardResponse.properties.data.properties), ['leaderboard']);

    assert.deepEqual(schemas.OrganizationLeaderboardRow.required, rowFields);
    assert.deepEqual(Object.keys(schemas.OrganizationLeaderboardRow.properties), rowFields);
    assert.deepEqual(schemas.OrganizationLeaderboardRow.properties.organization_key, { type: 'string' });
    assert.deepEqual(schemas.OrganizationLeaderboardRow.properties.organization_name, { type: ['string', 'null'] });
    assert.deepEqual(schemas.OrganizationLeaderboardRow.properties.point, {
        type: ['string', 'null'],
        description: 'Rounded sequence-point score serialized as a decimal string when present.',
    });
    assert.deepEqual(schemas.OrganizationLeaderboardRow.properties.total_length, {
        type: ['string', 'null'],
        description: 'Rounded total length in kilometers serialized with two decimal places when present.',
    });
    assert.deepEqual(schemas.OrganizationLeaderboardRow.properties.total_images, {
        type: 'integer',
        format: 'int64',
        minimum: 0,
    });
});

test('locks the exact optional global rate-limit error contract', () => {
    assert.deepEqual(schemas.PublicRateLimitError, {
        type: 'object',
        additionalProperties: false,
        required: ['success', 'message', 'error_code'],
        properties: {
            success: { const: false },
            message: {
                type: 'array',
                minItems: 1,
                maxItems: 1,
                items: { const: 'Too many requests.' },
            },
            error_code: { const: 429 },
        },
    });

    assert.deepEqual(operation.responses['429'].headers, {
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
    });
});

test('keeps synthetic populated and empty examples exact', () => {
    const response = operation.responses['200'].content['application/json'];

    assert.deepEqual(Object.keys(response.examples), ['populated', 'empty']);
    const populated = response.examples.populated.value;
    assert.deepEqual(Object.keys(populated), ['data']);
    assert.deepEqual(Object.keys(populated.data), ['leaderboard']);
    assert.deepEqual(populated.data.leaderboard.map((row) => Object.keys(row)), [rowFields, rowFields]);
    assert.equal(populated.data.leaderboard[0].organization_key, 'org-synthetic-a');
    assert.equal(typeof populated.data.leaderboard[0].point, 'string');
    assert.equal(typeof populated.data.leaderboard[0].total_length, 'string');
    assert.equal(typeof populated.data.leaderboard[0].total_images, 'number');
    assert.equal(populated.data.leaderboard[1].organization_name, null);
    assert.equal(populated.data.leaderboard[1].point, null);
    assert.equal(populated.data.leaderboard[1].total_length, null);
    assert.equal(populated.data.leaderboard[1].total_images, 0);
    assert.deepEqual(response.examples.empty.value, { data: { leaderboard: [] } });
    assert.equal('pagination' in populated, false);
});

test('guards the documented claims against route, source, cache, and fixture drift', async () => {
    const [routes, controller, query, cache, compatibility, cacheTest, limiter, rateLimitTest] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Organizations/OrganizationLeaderboardController.php'),
        readText('app/Domain/Organizations/Queries/OrganizationLeaderboardQuery.php'),
        readText('app/Support/Cache/PublicAggregateCache.php'),
        readText('tests/Feature/Legacy/OrganizationLeaderboardCompatibilityTest.php'),
        readText('tests/Feature/PublicAggregateCacheTest.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('tests/Feature/Security/ApiRateLimitTest.php'),
    ]);

    assert.match(routes, /Route::get\('organizations\/leaderboard', OrganizationLeaderboardController::class\)/);
    assert.match(controller, /route\('score_version', OrganizationLeaderboardQuery::SCORE_VERSION_SEQUENCE\)/);
    assert.match(controller, /organizationLeaderboard\(\s*\$scoreVersion/);
    assert.match(controller, /\$query->get\(\$scoreVersion\)/);
    assert.match(query, /public const SCORE_VERSION_SEQUENCE = 1/);
    assert.match(query, /AND organizations\.organization_key IS NOT NULL/);
    assert.match(query, /ORDER BY point DESC/);
    assert.match(query, /'organization_key' => \$row->organization_key === null \? null : \(string\)/);
    assert.match(query, /'point' => \$row->point === null \? null : number_format/);
    assert.match(query, /'total_length' => \$row->total_length === null \? null : number_format/);
    assert.match(query, /'total_images' => \(int\) \$row->total_images/);
    assert.match(cache, /ORGANIZATION_LEADERBOARD_KEY_PREFIX = 'mapilio:public:v1:organizations:leaderboard:score:'/);
    assert.match(compatibility, /\/api\/v1\/organizations\/leaderboard/);
    assert.match(cacheTest, /test_organization_leaderboard_v1_ignores_unrelated_query_keys/);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /if \(\$enforce\) \{\s*return \$this->tooManyRequests/s);
    assert.match(limiter, /'success' => false/);
    assert.match(limiter, /'message' => \['Too many requests\.'\]/);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    assert.match(limiter, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(limiter, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(limiter, /'X-RateLimit-Remaining' => '0'/);
    assert.match(rateLimitTest, /test_enforced_mode_returns_the_legacy_error_envelope/);
    assert.match(rateLimitTest, /'message' => \['Too many requests\.'\]/);
    assert.match(rateLimitTest, /headers->has\('Retry-After'\)/);
    assert.match(rateLimitTest, /headers->get\('X-RateLimit-Limit'\)/);
    assert.match(rateLimitTest, /headers->get\('X-RateLimit-Remaining'\)/);
});
