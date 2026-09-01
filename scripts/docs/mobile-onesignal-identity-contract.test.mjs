import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/mobile/onesignal/identity-verification']?.post;
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

test('documents only the versioned POST OneSignal identity contract', () => {
    assert.ok(operation, 'POST /api/v1/mobile/onesignal/identity-verification must be documented');
    assert.equal(specification.paths['/api/v1/mobile/onesignal/identity-verification'].get, undefined);
    assert.equal(operation.operationId, 'verifyMobileOneSignalIdentity');
    assert.deepEqual(operation.tags, ['Identity']);
    assert.deepEqual(operation.security, [{ mobileBearer: [] }]);
    assert.equal(operation.requestBody.required, false);
    assert.deepEqual(Object.keys(operation.requestBody.content), ['application/json', 'multipart/form-data']);
    assert.match(operation.description, /multipart\/form-data.*`options\[parameters\]\[email\]`/);
    assert.match(operation.description, /equivalent JSON object.*\{options:\{parameters:\{email\}\}\}/);
    assert.match(operation.description, /No request body is required.*omitted body.*documented 401 failure/);
    assert.match(operation.description, /controller-owned/);
    assert.match(operation.description, /active mobile user/);
    assert.match(operation.description, /exact and case-sensitive/);
    assert.match(operation.description, /server-side configuration.*configured OneSignal key.*signing key as fallback/);
    assert.match(operation.description, /no key value is accepted from or returned/);
    assert.match(operation.description, /no outbound OneSignal request/);
    assert.match(operation.description, /no endpoint-specific throttle.*response cache.*ETag/is);
    assert.match(operation.description, /deployment-optional global API limiter.*429.*not an endpoint-specific limiter/is);
    assert.match(operation.description, /does not claim exact failure-status parity/);
    assert.deepEqual(Object.keys(operation.responses), ['200', '401', '429']);
    assert.equal(operation.responses['403'], undefined);
    assert.equal(operation.responses['304'], undefined);
});

test('locks synthetic request, success, failure, and global limiter shapes', () => {
    const request = operation.requestBody.content['application/json'];
    assert.deepEqual(request.schema, {
        $ref: '#/components/schemas/MobileOneSignalIdentityVerificationRequest',
    });
    assert.deepEqual(request.example, {
        options: { parameters: { email: 'mapper@example.test' } },
    });

    const multipart = operation.requestBody.content['multipart/form-data'];
    assert.deepEqual(multipart.schema, {
        $ref: '#/components/schemas/MobileOneSignalIdentityVerificationMultipartRequest',
    });
    assert.deepEqual(multipart.example, {
        'options[parameters][email]': 'mapper@example.test',
    });

    assert.deepEqual(operation.responses['200'].content['application/json'].example, {
        status: true,
        response: {
            hash: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        },
    });
    assert.deepEqual(operation.responses['401'].content['application/json'], {
        schema: { $ref: '#/components/schemas/MobileOneSignalIdentityVerificationError' },
        example: { success: false, message: ['Verification failed.'] },
    });
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'], {
        schema: { $ref: '#/components/schemas/PublicRateLimitError' },
        example: { success: false, message: ['Too many requests.'], error_code: 429 },
    });
    assert.deepEqual(schemas.MobileOneSignalIdentityVerificationResponse, {
        type: 'object',
        additionalProperties: false,
        required: ['status', 'response'],
        properties: {
            status: { const: true },
            response: {
                type: 'object',
                additionalProperties: false,
                required: ['hash'],
                properties: { hash: { type: 'string', pattern: '^[a-f0-9]{64}$' } },
            },
        },
    });
    assert.deepEqual(schemas.MobileOneSignalIdentityVerificationError, {
        type: 'object',
        additionalProperties: false,
        required: ['success', 'message'],
        properties: {
            success: { const: false },
            message: {
                type: 'array',
                minItems: 1,
                maxItems: 1,
                items: { const: 'Verification failed.' },
            },
        },
    });
    assert.match(schemas.MobileOneSignalIdentityVerificationRequest.properties.options.properties.parameters.properties.email.type, /^string$/);
    assert.equal(schemas.MobileOneSignalIdentityVerificationMultipartRequest.properties['options[parameters][email]'].type, 'string');
});

test('guards route, controller, limiter, PHP evidence, and package registration', async () => {
    const [routes, controller, limiter, bootstrap, config, compatibility, packageSource] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Identity/OneSignalIdentityVerificationController.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('bootstrap/app.php'),
        readText('config/mapilio.php'),
        readText('tests/Feature/Legacy/MobileAuthCompatibilityTest.php'),
        readText('package.json'),
    ]);
    const versionedRoute = routes.match(/Route::post\('mobile\/onesignal\/identity-verification',[\s\S]*?->name\('mobile\.onesignal\.identity-verification'\);/);
    const legacyRoute = routes.match(/Route::post\('onesignal\/identity-verification',[\s\S]*?->name\('api\.legacy\.onesignal\.identity-verification'\);/);
    assert.ok(versionedRoute);
    assert.ok(legacyRoute);
    assert.match(versionedRoute[0], /OneSignalIdentityVerificationController::class/);
    assert.match(versionedRoute[0], /identity_verification_failure_status', 401/);
    assert.doesNotMatch(versionedRoute[0], /middleware|throttle/);
    assert.match(legacyRoute[0], /identity_verification_failure_status', 500/);

    assert.match(controller, /\$auth->userFromBearer\(\$request->header\('Authorization'\)\)/);
    assert.match(controller, /\(string\) data_get\(\$request->all\(\), 'options\.parameters\.email', ''\)/);
    assert.match(controller, /! hash_equals\(\(string\) \$user->email, \$email\)/);
    assert.match(controller, /config\('mapilio\.mobile_auth\.onesignal_rest_api_key'\)\s*\?: config\('mapilio\.mobile_auth\.signing_key'\)/s);
    assert.match(controller, /hash_hmac\('sha256', \$email, \$key\)/);
    assert.match(controller, /'Verification failed\.'/);
    assert.doesNotMatch(controller, /\b(?:Http::|curl_|Guzzle|PendingRequest|OneSignalClient)\b/i);
    assert.doesNotMatch(controller, /cache|etag|304/i);

    assert.match(bootstrap, /\$middleware->api\(append: \[\s*ThrottleApiRequests::class,/s);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /\|\| \$this->routeDeclaresOwnThrottle\(\$request\)/);
    assert.match(limiter, /\$enforce = \(bool\) config\('mapilio\.rate_limiting\.enforce', false\)/);
    assert.match(limiter, /'success' => false/);
    assert.match(limiter, /'message' => \['Too many requests\.'/);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    assert.match(limiter, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(limiter, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(limiter, /'X-RateLimit-Remaining' => '0'/);
    assert.match(config, /'enabled' => env\('MAPILIO_API_RATE_LIMITING_ENABLED', false\)/);
    assert.match(config, /'enforce' => env\('MAPILIO_API_RATE_LIMITING_ENFORCE', false\)/);

    assert.match(compatibility, /test_versioned_onesignal_identity_verification_accepts_equivalent_nested_form_request/);
    assert.match(compatibility, /test_versioned_onesignal_identity_verification_rejects_no_body_with_exact_failure/);
    assert.match(compatibility, /test_versioned_onesignal_identity_verification_rejects_coerced_and_case_sensitive_email_values/);
    assert.match(compatibility, /test_versioned_onesignal_identity_verification_rejects_unactivated_disabled_and_deleted_bearers/);
    assert.match(compatibility, /test_versioned_onesignal_identity_verification_rejects_stale_bearer/);
    assert.match(compatibility, /assertExactJson\(\[\s*'success' => false,\s*'message' => \['Verification failed\.'/s);

    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['test:mobile-onesignal-contract'], /scripts\/docs\/mobile-onesignal-identity-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/mobile-onesignal-identity-contract\.test\.mjs/);
});
