import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/imagery/leaderboard-winner']?.get;
const schemas = specification.components?.schemas ?? {};
const rowFields = [
    'id',
    'username',
    'display_name',
    'user_profile_photo',
    'point',
    'total_length',
    'total_images',
    'roles',
];

test('documents only the unauthenticated v1 leaderboard-winner alias', () => {
    assert.ok(operation, 'GET /api/v1/imagery/leaderboard-winner must be documented');
    assert.equal(specification.paths['/api/v1/imagery/leaderboard-winner'].post, undefined);
    assert.equal(operation.operationId, 'getImageryLeaderboardWinner');
    assert.deepEqual(operation.tags, ['Imagery reads']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required, allowEmptyValue }) => ({
        name,
        in: location,
        required,
        allowEmptyValue,
    })), [
        { name: 'user_id', in: 'query', required: false, allowEmptyValue: undefined },
        { name: 'start_at', in: 'query', required: false, allowEmptyValue: true },
        { name: 'finish_at', in: 'query', required: false, allowEmptyValue: true },
    ]);
    assert.deepEqual(operation.parameters.map(({ schema }) => schema), [
        { type: 'integer', format: 'int64', minimum: 1 },
        { type: 'string' },
        { type: 'string' },
    ]);
    assert.match(operation.description, /additive v1 alias of `GET \/api\/leaderboard-winner`/);
    assert.match(operation.description, /optional `user_id`, `start_at`, and `finish_at`/);
    assert.match(operation.description, /positive int64 value forwarded on calculated responses/);
    assert.match(operation.description, /same public user filter as `GET \/api\/v1\/imagery\/leaderboard`/);
    assert.match(operation.description, /no effect on flags-only responses/);
    assert.match(operation.description, /both date values are PHP-non-empty/);
    assert.match(operation.description, /missing or empty.*is_finished.*is_calculated.*no leaderboard key/s);
    assert.match(operation.description, /finish_at is earlier than the current server time/);
    assert.match(operation.description, /matching non-deleted challenge with exact start and finish dates/);
    assert.match(operation.description, /missing table, row, or value defaults to false/);
    assert.match(operation.description, /sequence-point scoring version 1/);
    assert.match(operation.description, /at most 3 rows/);
    assert.match(operation.description, /calculated empty result always contains `leaderboard: \[\]`, while `is_finished` remains the boolean determined by finish_at/);
    assert.doesNotMatch(operation.description, /calculated empty result is .*is_finished: true/s);
    assert.match(operation.description, /No pagination metadata, server-side response cache, or ETag/);
    assert.match(operation.description, /optional global API limiter.*deployment-configurable/s);
    assert.doesNotMatch(operation.description, /score_version|\/api\/v2\/leaderboard-winner|malformed|redact/i);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.match(operation.responses['200'].description, /conditional response shape is selected by the boolean data\.is_calculated value/);
});

test('locks the conditional flags-only and calculated response schemas', () => {
    assert.deepEqual(schemas.LeaderboardWinnerResponse, {
        type: 'object',
        additionalProperties: false,
        required: ['data'],
        properties: {
            data: {
                oneOf: [
                    { $ref: '#/components/schemas/LeaderboardWinnerFlags' },
                    { $ref: '#/components/schemas/LeaderboardWinnerCalculated' },
                ],
            },
        },
    });
    assert.equal('discriminator' in schemas.LeaderboardWinnerResponse.properties.data, false);
    assert.deepEqual(schemas.LeaderboardWinnerFlags, {
        type: 'object',
        additionalProperties: false,
        required: ['is_finished', 'is_calculated'],
        properties: {
            is_finished: { type: 'boolean' },
            is_calculated: { const: false },
        },
    });
    assert.deepEqual(schemas.LeaderboardWinnerCalculated, {
        type: 'object',
        additionalProperties: false,
        required: ['is_finished', 'is_calculated', 'leaderboard'],
        properties: {
            is_finished: { type: 'boolean' },
            is_calculated: { const: true },
            leaderboard: {
                type: 'array',
                maxItems: 3,
                items: { $ref: '#/components/schemas/LeaderboardRow' },
            },
        },
    });
    assert.deepEqual(schemas.LeaderboardRow.required, rowFields);
    assert.deepEqual(Object.keys(schemas.LeaderboardRow.properties), rowFields);
});

test('keeps synthetic status, calculated, empty, and rate-limit examples exact', () => {
    const responses = operation.responses;
    const success = responses['200'].content['application/json'];

    assert.deepEqual(Object.keys(success.examples), ['flags-only', 'flags-only-finished', 'calculated-populated', 'calculated-empty']);
    assert.deepEqual(success.examples['flags-only'].value, {
        data: { is_finished: false, is_calculated: false },
    });
    assert.deepEqual(success.examples['flags-only-finished'].value, {
        data: { is_finished: true, is_calculated: false },
    });

    const populated = success.examples['calculated-populated'].value;
    assert.deepEqual(Object.keys(populated), ['data']);
    assert.deepEqual(Object.keys(populated.data), ['is_finished', 'is_calculated', 'leaderboard']);
    assert.equal(populated.data.is_finished, true);
    assert.equal(populated.data.is_calculated, true);
    assert.equal(populated.data.leaderboard.length, 3);
    for (const row of populated.data.leaderboard) {
        assert.deepEqual(Object.keys(row), rowFields);
        assert.equal(typeof row.id, 'number');
        assert.ok(row.username === null || typeof row.username === 'string');
        assert.ok(row.display_name === null || typeof row.display_name === 'string');
        assert.ok(row.user_profile_photo === null || typeof row.user_profile_photo === 'string');
        assert.equal(typeof row.point, 'string');
        assert.equal(typeof row.total_length, 'string');
        assert.equal(typeof row.total_images, 'number');
        assert.ok(row.roles === null || typeof row.roles === 'string');
    }
    assert.deepEqual(success.examples['calculated-empty'].value, {
        data: { is_finished: true, is_calculated: true, leaderboard: [] },
    });
    assert.equal('pagination' in populated, false);

    assert.deepEqual(responses['429'].headers, {
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
    assert.deepEqual(responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('guards documented claims against route, source, and test drift', async () => {
    const [routes, controller, winnerQuery, leaderboardQuery, compatibility, rateLimiter] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Imagery/LeaderboardWinnerController.php'),
        readText('app/Domain/ImagerySequences/Queries/LeaderboardWinnerQuery.php'),
        readText('app/Domain/ImagerySequences/Queries/LeaderboardQuery.php'),
        readText('tests/Feature/Legacy/LeaderboardWinnerCompatibilityTest.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
    ]);

    assert.match(routes, /Route::get\('imagery\/leaderboard-winner', LeaderboardWinnerController::class\)/);
    assert.match(controller, /\$query->get\(\$request->query\(\)\)/);
    assert.match(winnerQuery, /if \(empty\(\$filters\['start_at'\]\) \|\| empty\(\$filters\['finish_at'\]\)\)/);
    assert.match(winnerQuery, /Carbon::parse\(\$filters\['start_at'\]\)/);
    assert.match(winnerQuery, /Carbon::parse\(\$filters\['finish_at'\]\)/);
    assert.match(winnerQuery, /\$finishAt->lessThan\(Carbon::now\(\)\)/);
    assert.match(winnerQuery, /whereDate\('start_at', \$startAt\)/);
    assert.match(winnerQuery, /whereDate\('finish_at', \$finishAt\)/);
    assert.match(winnerQuery, /whereNull\('deleted_at'\)/);
    assert.match(winnerQuery, /\$payload\['leaderboard'\] = \$this->leaderboardQuery->get\(\$filters, 3\)/);
    assert.match(leaderboardQuery, /optionalUserId/);
    assert.match(leaderboardQuery, /public const SCORE_VERSION_SEQUENCE = 1/);
    assert.match(leaderboardQuery, /'point' => number_format/);
    assert.match(leaderboardQuery, /'total_length' => number_format/);
    assert.match(leaderboardQuery, /'total_images' => \(int\)/);
    assert.match(leaderboardQuery, /'roles' => \$this->normaliseRoles/);
    assert.match(rateLimiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(rateLimiter, /'success' => false/);
    assert.match(rateLimiter, /'message' => \['Too many requests\.'\]/);
    assert.match(rateLimiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    assert.match(compatibility, /\/api\/v1\/imagery\/leaderboard-winner/);
    assert.match(compatibility, /test_versioned_leaderboard_winner_calculated_response_matches_legacy/);
    assert.match(compatibility, /test_versioned_leaderboard_winner_forwards_valid_user_id_on_calculated_path/);
    assert.match(compatibility, /test_versioned_leaderboard_winner_partial_date_returns_flags_only/);
    assert.match(compatibility, /test_versioned_leaderboard_winner_finish_boundaries_are_deterministic/);
    assert.match(compatibility, /Carbon::setTestNow\(null\)/);
});
