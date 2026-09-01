import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/mobile/auth/logout']?.post;
const schemas = specification.components?.schemas ?? {};

test('documents only the controller-owned mobile logout contract', () => {
    assert.ok(operation, 'POST /api/v1/mobile/auth/logout must be documented');
    assert.equal(specification.paths['/api/v1/mobile/auth/logout'].get, undefined);
    assert.equal(operation.operationId, 'logoutMobileSession');
    assert.deepEqual(operation.tags, ['Identity']);
    assert.deepEqual(operation.security, [{ mobileBearer: [] }]);
    assert.equal(operation.requestBody.required, false);
    assert.deepEqual(operation.requestBody.content['application/json'].schema, {
        $ref: '#/components/schemas/MobileLogoutRequest',
    });
    assert.deepEqual(operation.responses['200'].content['application/json'].example, {
        success: true,
        message: ['Signed out.'],
    });
    assert.deepEqual(operation.responses['401'].content['application/json'].example, {
        success: false,
        message: ['Unauthorized'],
    });
    assert.deepEqual(Object.keys(operation.responses), ['200', '401', '422', '429']);
    assert.match(operation.description, /controller-owned/);
    assert.match(operation.description, /userFromBearer/);
    assert.match(operation.description, /Missing, invalid, stale, deleted-user, unactivated-user, and disabled-user/);
    assert.match(operation.description, /validation runs before bearer lookup/);
    assert.match(operation.description, /explicit JSON null is accepted and treated as absent/);
    assert.match(operation.description, /absent or any value other than exactly `refresh_token` uses the password\/IP bucket/);
    assert.match(operation.description, /raw `grant_type=refresh_token` uses the refresh\/IP bucket/);
    assert.match(operation.description, /MAPILIO_MOBILE_AUTH_REVOCATION_ENABLED=true/);
    assert.match(operation.description, /no endpoint-specific cache or ETag/);
    assert.match(operation.description, /does not define a 304 response/);
});

test('freezes logout inputs, response envelopes, malformed refresh behavior, and rate limiting', () => {
    assert.deepEqual(schemas.MobileLogoutRequest, {
        type: 'object',
        properties: {
            refresh_token: { type: ['string', 'null'], maxLength: 4096 },
        },
    });
    assert.deepEqual(operation.responses['422'].content['application/json'].example, {
        message: 'The refresh token field must not be greater than 4096 characters.',
        errors: {
            refresh_token: ['The refresh token field must not be greater than 4096 characters.'],
        },
    });
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many authentication attempts. Please try again later.'],
    });
    assert.deepEqual(schemas.MobileLogoutResponse.properties.message.items, { const: 'Signed out.' });
    assert.deepEqual(schemas.MobileLogoutUnauthorizedError.properties.message.items, { const: 'Unauthorized' });
    assert.deepEqual(schemas.MobileAuthRateLimitError.properties.message.items, {
        const: 'Too many authentication attempts. Please try again later.',
    });
    assert.equal(operation.responses['304'], undefined);
    assert.equal(operation.headers, undefined);
});

test('guards the local route, runtime source, focused PHP assertions, and package registration', async () => {
    const [routes, controller, auth, limiter, config, phpTest, packageSource] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Api/V1/Mobile/MobileLogoutController.php'),
        readText('app/Domain/IdentityAccess/LegacyMobileAuth.php'),
        readText('app/Providers/AppServiceProvider.php'),
        readText('config/mapilio.php'),
        readText('tests/Feature/Security/TokenRevocationTest.php'),
        readText('package.json'),
    ]);
    const route = routes.match(/Route::post\('mobile\/auth\/logout',[\s\S]*?->name\('mobile\.auth\.logout'\);/);
    assert.ok(route);
    assert.match(route[0], /MobileLogoutController::class/);
    assert.match(route[0], /->middleware\('throttle:mobile-auth'\)/);
    assert.doesNotMatch(route[0], /->middleware\('mobile\.auth'\)/);

    assert.match(controller, /'refresh_token' => \['nullable', 'string', 'max:4096'\]/);
    assert.match(controller, /\$auth->userFromBearer\(\$header\) === null/);
    assert.match(controller, /'success' => false,\s*'message' => \['Unauthorized'\]/s);
    assert.match(controller, /\$auth->revokeToken\(\$accessToken, 'access', 'logout'\)/);
    assert.match(controller, /\$auth->revokeToken\(\$refreshToken, 'refresh', 'logout'\)/);
    assert.doesNotMatch(controller, /cache|etag|304/i);
    assert.ok(
        controller.indexOf('$validated = $request->validate') < controller.indexOf('$header ='),
        'refresh validation must precede bearer lookup',
    );

    assert.match(auth, /\|\| \(int\) \$payload\['exp'\] < time\(\)/);
    assert.match(auth, /\$user === null \|\| ! \(bool\) \$user->activated \|\| ! \(bool\) \$user->enabled/);
    assert.match(auth, /count\(\$parts\) !== 2 \|\| ! \$this->signatureIsValid/);
    assert.match(limiter, /RateLimiter::for\('mobile-auth'/);
    assert.match(limiter, /\$grantType = \$request->input\('grant_type'\) === 'refresh_token'\s*\? 'refresh'\s*:\s*'password'/);
    assert.match(limiter, /->by\('mobile-auth\|'\.\$grantType\.'\|'\.\$request->ip\(\)\)/);
    assert.match(limiter, /Too many authentication attempts\. Please try again later\./);
    assert.match(config, /env\('MAPILIO_MOBILE_AUTH_REVOCATION_ENABLED', false\)/);

    assert.match(phpTest, /test_logout_requires_a_valid_token/);
    assert.match(phpTest, /test_logout_validates_refresh_tokens_and_ignores_malformed_strings/);
    assert.match(phpTest, /test_logout_accepts_null_refresh_token_as_absent/);
    assert.match(phpTest, /test_logout_rejects_stale_and_unavailable_bearers_with_exact_envelope/);
    assert.match(phpTest, /test_logout_uses_raw_grant_type_to_select_shared_auth_bucket/);
    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['test:mobile-logout-contract'], /scripts\/docs\/mobile-logout-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/mobile-logout-contract\.test\.mjs/);
});
