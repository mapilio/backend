import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/mobile/auth/public-token']?.post;
const schemas = specification.components?.schemas ?? {};

const expectedToken = {
    id: 10,
    success: true,
    token_type: 'Bearer',
    expires_in: 3600,
};

test('documents only the public-client mobile token POST contract', () => {
    assert.ok(operation, 'POST /api/v1/mobile/auth/public-token must be documented');
    assert.equal(specification.paths['/api/v1/mobile/auth/public-token'].get, undefined);
    assert.equal(operation.operationId, 'issueMobilePublicToken');
    assert.deepEqual(operation.tags, ['Identity']);
    assert.deepEqual(operation.security, []);
    assert.match(operation.description, /public client/);
    assert.match(operation.description, /email or username/);
    assert.match(operation.description, /email.*precedence/);
    assert.match(operation.description, /refresh_token grant/);
    assert.match(operation.description, /application\/json and multipart\/form-data/);
    assert.deepEqual(Object.keys(operation.requestBody.content), ['application/json', 'multipart/form-data']);
    for (const mediaType of Object.values(operation.requestBody.content)) {
        assert.deepEqual(mediaType.schema, { $ref: '#/components/schemas/MobilePublicTokenRequest' });
        assert.deepEqual(Object.keys(mediaType.examples), [
            'passwordEmail',
            'passwordUsername',
            'passwordEmailPrecedence',
            'refresh',
        ]);
    }
    assert.deepEqual(Object.keys(operation.responses), ['200', '400', '422', '429', '503']);
});

test('locks password identifiers, precedence, refresh input, and shared token response', () => {
    const request = schemas.MobilePublicTokenRequest;
    assert.equal(request.oneOf.length, 2);

    const password = request.oneOf.find((branch) => branch.properties.grant_type.const === 'password');
    const refresh = request.oneOf.find((branch) => branch.properties.grant_type.const === 'refresh_token');
    assert.deepEqual(password.required, ['grant_type', 'password']);
    assert.deepEqual(password.anyOf, [
        {
            required: ['email'],
            properties: { email: { type: 'string', maxLength: 254 } },
        },
        {
            required: ['username'],
            properties: { username: { type: 'string', maxLength: 254 } },
        },
    ]);
    assert.deepEqual(Object.keys(password.properties), ['grant_type', 'email', 'username', 'password']);
    assert.deepEqual(password.properties.email, { type: 'string', maxLength: 254 });
    assert.deepEqual(password.properties.username, { type: 'string', maxLength: 254 });
    assert.deepEqual(password.properties.password, { type: 'string', maxLength: 1024 });
    assert.deepEqual(refresh.required, ['grant_type', 'refresh_token']);
    assert.deepEqual(refresh.properties.refresh_token, { type: 'string', maxLength: 4096 });

    const response = operation.responses['200'].content['application/json'];
    assert.deepEqual(response.schema, { $ref: '#/components/schemas/WebTokenResponse' });
    for (const example of Object.values(response.examples)) {
        assert.deepEqual(Object.keys(example.value), [...Object.keys(expectedToken), 'access_token', 'refresh_token']);
        assert.deepEqual(Object.fromEntries(Object.keys(expectedToken).map((key) => [key, example.value[key]])), expectedToken);
        assert.match(example.value.access_token, /^synthetic-/);
        assert.match(example.value.refresh_token, /^synthetic-/);
    }
});

test('freezes the exact current auth error envelopes', () => {
    const responses = operation.responses;
    const badRequest = responses['400'].content['application/json'];
    assert.deepEqual(badRequest.schema, { $ref: '#/components/schemas/MobilePublicTokenError' });
    assert.deepEqual(badRequest.examples.invalidCredentials.value, {
        success: false,
        message: ['Email or password is invalid.'],
    });
    assert.deepEqual(badRequest.examples.missingIdentifier.value, {
        success: false,
        message: {
            username: ['username or email parameter is required!'],
            email: ['username or email parameter is required!'],
        },
    });
    assert.deepEqual(schemas.MobilePublicTokenError.properties.message.anyOf, [
        { type: 'string' },
        { type: 'array', items: { type: 'string' } },
        { $ref: '#/components/schemas/MobilePublicTokenMissingIdentifierMessage' },
    ]);
    assert.deepEqual(schemas.MobilePublicTokenMissingIdentifierMessage.required, ['username', 'email']);
    assert.deepEqual(schemas.MobilePublicTokenMissingIdentifierMessage.properties.username.items, {
        const: 'username or email parameter is required!',
    });
    assert.deepEqual(responses['422'].content['application/json'].example, {
        message: 'The grant type field is required.',
        errors: { grant_type: ['The grant type field is required.'] },
    });
    assert.deepEqual(responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many authentication attempts. Please try again later.'],
    });
    assert.deepEqual(responses['503'].content['application/json'].example, {
        success: false,
        message: 'Authentication service is temporarily unavailable.',
    });
    assert.deepEqual(schemas.WebTokenResponse.required, [
        'id',
        'success',
        'token_type',
        'expires_in',
        'access_token',
        'refresh_token',
    ]);
});

test('guards repository-local route, limiter, controller, source precedence, PHP behavior tests, and package registration', async () => {
    const [routes, limiter, controller, auth, exception, phpTest, packageSource] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Providers/AppServiceProvider.php'),
        readText('app/Http/Controllers/Api/V1/Mobile/MobilePublicTokenController.php'),
        readText('app/Domain/IdentityAccess/LegacyMobileAuth.php'),
        readText('app/Domain/IdentityAccess/LegacyMobileAuthException.php'),
        readText('tests/Feature/Security/MobilePublicClientAuthTest.php'),
        readText('package.json'),
    ]);
    const route = routes.match(/Route::post\('mobile\/auth\/public-token',[\s\S]*?->name\('mobile\.auth\.public-token'\);/);
    assert.ok(route);
    assert.match(route[0], /MobilePublicTokenController::class/);
    assert.match(route[0], /->middleware\('throttle:mobile-auth'\)/);
    const mobileLimiter = limiter.match(/RateLimiter::for\('mobile-auth',[\s\S]*?\n        \}\);/);
    assert.ok(mobileLimiter);
    assert.match(mobileLimiter[0], /grant_type.*refresh_token/);
    assert.match(mobileLimiter[0], /mobile-auth\|'\.\$grantType\.'\|'\.\$request->ip\(\)/);
    assert.match(mobileLimiter[0], /Too many authentication attempts\. Please try again later\./);

    assert.match(controller, /'grant_type' => \['required', 'string', 'in:password,refresh_token'\]/);
    assert.match(controller, /'email' => \['nullable', 'string', 'max:254'\]/);
    assert.match(controller, /'username' => \['nullable', 'string', 'max:254'\]/);
    assert.match(controller, /'password' => \['nullable', 'required_if:grant_type,password', 'string', 'max:1024'\]/);
    assert.match(controller, /'refresh_token' => \['nullable', 'required_if:grant_type,refresh_token', 'string', 'max:4096'\]/);
    assert.match(controller, /\$request->validate\(/);
    assert.match(controller, /\$auth->issueFirstPartyToken\(\$validated\)/);
    assert.match(controller, /'message' => \$exception->legacyMessage\(\)/);
    assert.match(controller, /'message' => 'Authentication service is temporarily unavailable\.'/);
    assert.match(auth, /\$login = \(string\) \(\$parameters\['email'\] \?\? \$parameters\['username'\] \?\? ''\)/);
    assert.match(exception, /public static function validation\(array \$errors\): self/);
    assert.match(exception, /return new self\(\$errors, 400\)/);
    assert.match(exception, /public function legacyMessage\(\): array\|string/);

    assert.match(phpTest, /test_public_client_missing_password_identifier_uses_the_legacy_400_shape/);
    assert.match(phpTest, /test_public_client_form_password_login_issues_tokens/);
    assert.match(phpTest, /test_public_client_form_refresh_grant_rotates_tokens/);
    assert.match(phpTest, /test_public_client_form_email_takes_precedence_over_username/);

    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/mobile-public-token-contract\.test\.mjs/);
});
