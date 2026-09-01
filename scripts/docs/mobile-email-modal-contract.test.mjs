import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/mobile/profile/email-modal']?.post;
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
        description: 'Requests remaining in the current limiter window, serialized as a decimal header value.',
        schema: { const: '0' },
    },
};

test('documents only the versioned POST email-modal contract', () => {
    assert.ok(operation, 'POST /api/v1/mobile/profile/email-modal must be documented');
    assert.equal(specification.paths['/api/v1/mobile/profile/email-modal'].get, undefined);
    assert.equal(operation.operationId, 'checkMobileEmailModal');
    assert.deepEqual(operation.tags, ['Identity']);
    assert.deepEqual(operation.security, [{ mobileBearer: [] }]);
    assert.equal(operation.requestBody, undefined, 'the legacy-compatible request has an empty body');
    assert.deepEqual(operation.parameters ?? [], []);
    assert.match(operation.description, /empty POST body/);
    assert.match(operation.description, /`POST \/api\/function\/user_profile\/profile\/checkIsModalShown`/);
    assert.match(operation.description, /first call.*\{status: false\}.*one profile row/s);
    assert.match(operation.description, /repeat call.*\{status: true\}.*without inserting another row/s);
    assert.match(operation.description, /mobile bearer/);
    assert.match(operation.description, /no endpoint-specific throttle.*response cache.*ETag/is);
    assert.match(operation.description, /optional global API limiter.*429.*deployment-configurable/is);
    assert.deepEqual(Object.keys(operation.responses), ['200', '401', '429']);
    assert.equal(operation.responses['403'], undefined);
});

test('locks exact synthetic success and bearer failure examples', () => {
    const success = operation.responses['200'].content['application/json'];
    assert.deepEqual(success.schema, { $ref: '#/components/schemas/MobileEmailModalResponse' });
    assert.deepEqual(Object.keys(success.examples), ['firstCall', 'repeatCall']);
    assert.deepEqual(success.examples.firstCall.value, { status: false });
    assert.deepEqual(success.examples.repeatCall.value, { status: true });

    const unauthenticated = operation.responses['401'].content['application/json'];
    assert.deepEqual(unauthenticated.schema, {
        $ref: '#/components/schemas/MobileEmailModalUnauthenticatedError',
    });
    assert.deepEqual(unauthenticated.example, { message: 'Unauthenticated.' });
    assert.match(operation.responses['401'].description, /missing, invalid, or no longer represents an active, enabled, non-deleted user/);
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'], {
        schema: { $ref: '#/components/schemas/PublicRateLimitError' },
        example: { success: false, message: ['Too many requests.'], error_code: 429 },
    });
    assert.deepEqual(schemas.MobileEmailModalResponse, {
        type: 'object',
        additionalProperties: false,
        required: ['status'],
        properties: { status: { type: 'boolean' } },
    });
    assert.deepEqual(schemas.MobileEmailModalUnauthenticatedError, {
        type: 'object',
        additionalProperties: false,
        required: ['message'],
        properties: { message: { const: 'Unauthenticated.' } },
    });
});

test('guards route, middleware, controller, action, limiter, legacy parity, PHP evidence, and package registration', async () => {
    const [routes, controller, action, bootstrap, limiter, config, compatibility, packageSource] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Identity/CheckMobileEmailModalController.php'),
        readText('app/Domain/IdentityAccess/Actions/CheckMobileEmailModal.php'),
        readText('bootstrap/app.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('config/mapilio.php'),
        readText('tests/Feature/Legacy/MobileEmailModalCompatibilityTest.php'),
        readText('package.json'),
    ]);

    const legacyRoute = routes.match(/Route::post\('function\/user_profile\/profile\/checkIsModalShown',[\s\S]*?->name\('api\.legacy\.mobile-profile\.email-modal'\);/);
    const versionedRoute = routes.match(/Route::post\('mobile\/profile\/email-modal',[\s\S]*?->name\('mobile\.profile\.email-modal'\);/);
    assert.ok(legacyRoute);
    assert.ok(versionedRoute);
    for (const route of [legacyRoute[0], versionedRoute[0]]) {
        assert.match(route, /CheckMobileEmailModalController::class/);
        assert.match(route, /->middleware\('mobile\.auth'\)/);
        assert.doesNotMatch(route, /throttle/);
    }

    assert.match(controller, /mapilio_mobile_user/);
    assert.match(controller, /if \(! is_object\(\$user\) \|\| ! isset\(\$user->id\)\)/);
    assert.match(controller, /FILTER_VALIDATE_INT/);
    assert.match(controller, /\$modal->check\(\$user\)/);
    assert.match(controller, /'message' => 'Unauthenticated\.'/);

    assert.match(action, /->whereNull\('deleted_at'\)/);
    assert.match(action, /->where\('activated', true\)/);
    assert.match(action, /->where\('enabled', true\)/);
    assert.match(action, /->lockForUpdate\(\)/);
    assert.match(action, /->transaction\(/);
    assert.match(action, /->exists\(\)/);
    assert.match(action, /->insert\(\[/);
    assert.match(action, /'created_by_id' => \(int\) \$user->id/);
    assert.match(action, /'updated_by_id' => \(int\) \$user->id/);
    assert.match(action, /return \['status' => \$isShown\]/);
    assert.match(action, /throw new AuthenticationException/);

    assert.match(bootstrap, /\$middleware->api\(append: \[\s*ThrottleApiRequests::class,/s);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /\|\| \$this->routeDeclaresOwnThrottle\(\$request\)/);
    assert.match(limiter, /\$enforce = \(bool\) config\('mapilio\.rate_limiting\.enforce', false\)/);
    assert.match(limiter, /if \(\$enforce\) \{\s*return \$this->tooManyRequests/s);
    assert.match(limiter, /'success' => false/);
    assert.match(limiter, /'message' => \['Too many requests\.'\]/);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    assert.match(limiter, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(limiter, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(limiter, /'X-RateLimit-Remaining' => '0'/);
    assert.match(config, /'enabled' => env\('MAPILIO_API_RATE_LIMITING_ENABLED', false\)/);
    assert.match(config, /'enforce' => env\('MAPILIO_API_RATE_LIMITING_ENFORCE', false\)/);

    assert.match(compatibility, /\/api\/function\/user_profile\/profile\/checkIsModalShown/);
    assert.match(compatibility, /\/api\/v1\/mobile\/profile\/email-modal/);
    assert.match(compatibility, /test_mobile_check_email_modal_repeat_call_returns_false_then_true_and_creates_one_record/);
    assert.match(compatibility, /test_mobile_check_email_modal_requires_valid_bearer_token/);
    assert.match(compatibility, /test_mobile_check_email_modal_aliases_use_mobile_auth_middleware/);
    assert.match(compatibility, /test_versioned_mobile_check_email_modal_alias_matches_legacy_contract/);
    assert.match(compatibility, /test_versioned_mobile_check_email_modal_rejects_token_after_user_is_unactivated/);
    assert.match(compatibility, /test_versioned_mobile_check_email_modal_rejects_token_after_user_is_disabled/);
    assert.match(compatibility, /'activated' => false/);
    assert.match(compatibility, /'enabled' => false/);
    assert.match(compatibility, /assertExactJson\(\[\s*'status' => false/s);
    assert.match(compatibility, /assertExactJson\(\[\s*'status' => true/s);
    assert.match(compatibility, /assertSame\(1, Schema::getConnection\(\)->table\('default_user_profile_profile'\)->count\(\)\)/);
    assert.match(compatibility, /'message' => 'Unauthenticated\.'/);

    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/mobile-email-modal-contract\.test\.mjs/);
});
