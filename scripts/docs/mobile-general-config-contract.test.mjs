import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/mobile/config/general']?.get;
const schemas = specification.components?.schemas ?? {};

const configFields = [
    'isMarketOpen',
    'isChallengeOpen',
    'leaderboard',
    'socialLogin',
    'versions',
    'map',
    'mapTokens',
    'osmModal',
];
const leaderboardFields = [
    'challengeDescEN',
    'challengeDescTR',
    'challengeDates',
    'challengeURL',
    'isChallengeOpen',
    'infoBoxDescTR',
    'infoBoxDescEN',
    'isInfoBoxOpen',
    'showWeek',
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

test('documents only the unauthenticated mobile general config GET contract', () => {
    assert.ok(operation, 'GET /api/v1/mobile/config/general must be documented');
    assert.equal(operation.operationId, 'getMobileGeneralConfig');
    assert.deepEqual(operation.tags, ['Identity']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required, deprecated }) => ({
        name,
        in: location,
        required,
        ...(deprecated ? { deprecated } : {}),
    })), [
        { name: 'X-Mapilio-Config-Token', in: 'header', required: false },
        { name: 'token', in: 'query', required: false, deprecated: true },
    ]);
    assert.match(operation.description, /exact `\{config: \.\.\.\}` configuration tree/);
    assert.match(operation.description, /non-empty string.*matching token.*either.*X-Mapilio-Config-Token.*deprecated.*\?token=/i);
    assert.match(operation.description, /only a non-empty header takes precedence.*empty or omitted header falls back/i);
    assert.match(operation.description, /empty, unset, or non-string configured server token allows access/i);
    assert.doesNotMatch(operation.parameters[0].description, /required/i);
    assert.match(operation.description, /no endpoint authentication.*response cache.*ETag.*304/i);
    assert.match(operation.description, /optional global API limiter.*429.*deployment-configurable/is);
    assert.deepEqual(Object.keys(operation.responses), ['200', '403', '429']);
    assert.equal(operation.responses['200'].headers, undefined);
    assert.equal(operation.responses['304'], undefined);
    assert.equal(specification.paths['/config/general'], undefined);
});

test('locks the env-backed config tree and exact synthetic examples', () => {
    assert.deepEqual(schemas.MobileGeneralConfig.required, configFields);
    assert.deepEqual(Object.keys(schemas.MobileGeneralConfig.properties), configFields);
    assert.deepEqual(schemas.MobileGeneralConfigResponse.required, ['config']);
    assert.deepEqual(schemas.MobileGeneralConfigResponse.properties.config, {
        $ref: '#/components/schemas/MobileGeneralConfig',
    });

    assert.deepEqual(schemas.MobileGeneralConfigLeaderboard.required, leaderboardFields);
    assert.deepEqual(Object.keys(schemas.MobileGeneralConfigLeaderboard.properties), leaderboardFields);
    assert.deepEqual(schemas.MobileGeneralConfigLeaderboard.properties.challengeURL, {
        type: ['string', 'boolean', 'null'],
    });
    assert.deepEqual(schemas.MobileGeneralConfigSocialLogin.properties.isOSMEnabled, {
        type: ['boolean', 'string', 'null'],
    });
    assert.deepEqual(schemas.MobileGeneralConfigVersion.properties.version, {
        type: ['string', 'boolean', 'null'],
    });
    assert.deepEqual(schemas.MobileGeneralConfigMap.properties.iosToken, {
        type: ['string', 'boolean', 'null'],
    });
    assert.deepEqual(schemas.MobileGeneralConfigOsmModal.properties.descriptionTR, {
        type: ['string', 'boolean', 'null'],
    });

    const response = operation.responses['200'].content['application/json'];
    assert.deepEqual(Object.keys(response.example), ['config']);
    assert.deepEqual(Object.keys(response.example.config), configFields);
    assert.deepEqual(Object.keys(response.example.config.leaderboard), leaderboardFields);
    assert.match(response.example.config.leaderboard.challengeURL, /^https:\/\/example\.test\//);
    assert.match(response.example.config.map.iosToken, /^synthetic-/);
    assert.match(response.example.config.mapTokens.androidToken, /^synthetic-/);
    assert.doesNotMatch(JSON.stringify(response.example), /mapilio\.com/i);

    assert.deepEqual(operation.responses['403'].content['application/json'].example, {
        success: false,
        message: ['Forbidden'],
    });
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('guards route, controller, limiter, config, PHP behavior, and package registration', async () => {
    const [routes, controller, bootstrap, limiter, config, phpTest, packageSource] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Config/GeneralConfigController.php'),
        readText('bootstrap/app.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('config/mapilio.php'),
        readText('tests/Feature/Legacy/MobileConfigCompatibilityTest.php'),
        readText('package.json'),
    ]);
    const packageJson = JSON.parse(packageSource);
    const route = routes.match(/Route::get\('mobile\/config\/general', GeneralConfigController::class\)[\s\S]*?->name\('mobile\.config\.general'\);/);

    assert.ok(route);
    assert.doesNotMatch(route[0], /middleware|auth/i);
    assert.match(controller, /is_string\(\$expectedToken\) && \$expectedToken !== ''/);
    assert.match(controller, /\$header = \$request->header\('X-Mapilio-Config-Token'\)/);
    assert.match(controller, /is_string\(\$header\) && \$header !== ''/);
    assert.match(controller, /return \(string\) \$request->query\('token', ''\)/);
    assert.match(controller, /'config' => config\('mapilio\.mobile_config\.general'\)/);
    assert.doesNotMatch(controller, /Cache::|cache\(|setEtag|isNotModified|HTTP_NOT_MODIFIED/i);
    assert.match(bootstrap, /\$middleware->api\(append: \[\s*ThrottleApiRequests::class,/s);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /\$enforce = \(bool\) config\('mapilio\.rate_limiting\.enforce', false\)/);
    assert.match(limiter, /return \$request->is\('api\/\*'\)/);
    assert.match(limiter, /'message' => \['Too many requests\.'\]/);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    assert.match(limiter, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(limiter, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(limiter, /'X-RateLimit-Remaining' => '0'/);
    assert.match(config, /'token' => env\('MAPILIO_MOBILE_CONFIG_TOKEN'\)/);
    assert.match(config, /'enabled' => env\('MAPILIO_API_RATE_LIMITING_ENABLED', false\)/);
    assert.match(config, /'enforce' => env\('MAPILIO_API_RATE_LIMITING_ENFORCE', false\)/);
    assert.match(config, /'isMarketOpen' => env\('MAPILIO_MOBILE_MARKET_OPEN', false\)/);
    assert.match(config, /'challengeURL' => env\('MAPILIO_MOBILE_CHALLENGE_URL'/);
    assert.match(config, /'iosToken' => env\('MAPILIO_MOBILE_IOS_MAP_TOKEN', ''\)/);
    assert.match(phpTest, /test_versioned_mobile_general_config_prefers_header_token_over_legacy_query_token/);
    assert.match(phpTest, /test_versioned_mobile_general_config_empty_header_falls_back_to_valid_legacy_query_token/);
    assert.match(phpTest, /test_versioned_mobile_general_config_allows_access_when_server_token_is_empty_unset_or_non_string/);
    assert.match(phpTest, /test_versioned_mobile_general_config_optional_global_rate_limit_preserves_exact_envelope_and_headers/);
    assert.match(phpTest, /test_versioned_mobile_general_config_ignores_conditional_headers_and_emits_no_etag/);
    assert.match(packageJson.scripts['test:mobile-general-config-contract'], /node --test scripts\/docs\/mobile-general-config-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/mobile-general-config-contract\.test\.mjs/);
});
