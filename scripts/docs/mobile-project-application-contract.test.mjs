import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/projects/jobs']?.post;
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

test('documents only the authenticated versioned project-job POST', () => {
    assert.ok(operation, 'POST /api/v1/projects/jobs must be documented');
    assert.equal(specification.paths['/api/v1/projects/jobs'].get, undefined);
    assert.equal(operation.operationId, 'createMobileProjectJob');
    assert.deepEqual(operation.tags, ['Projects']);
    assert.deepEqual(operation.security, [{ mobileBearer: [] }]);
    assert.equal(operation.requestBody.required, false);
    assert.deepEqual(Object.keys(operation.requestBody.content), ['application/json']);
    assert.match(operation.description, /`POST \/api\/function\/projects\/job\/createJob`/);
    assert.match(operation.description, /synchronous/);
    assert.match(operation.description, /transaction.*locks.*active authenticated user row/s);
    assert.match(operation.description, /does not enqueue work/);
    assert.match(operation.description, /soft-deleted project.*not found.*soft-deleted existing job does not count/s);
    assert.match(operation.description, /options\.parameters\.id.*first/);
    assert.match(operation.description, /present.*null or invalid.*blocks.*top-level `id` fallback/s);
    assert.match(operation.description, /PHP `is_numeric`.*cast to integer.*positive after the cast/s);
    assert.match(operation.description, /no endpoint-specific throttle.*response cache.*ETag.*idempotency key.*unique constraint/is);
    assert.match(operation.description, /generic database or runtime 500 responses are outside this contract/);
    assert.match(operation.description, /optional global API limiter.*429/is);
    assert.deepEqual(Object.keys(operation.responses), ['200', '400', '401', '403', '429', '500']);
});

test('locks request shapes and exact response envelopes', () => {
    const request = schemas.MobileProjectJobCreateRequest;
    assert.equal(request.type, 'object');
    assert.equal(request.additionalProperties, true);
    assert.deepEqual(request.properties.id.type, ['integer', 'number', 'string', 'null']);
    assert.deepEqual(request.properties.options.properties.parameters.properties.id.type, [
        'integer',
        'number',
        'string',
        'null',
    ]);
    assert.deepEqual(operation.requestBody.content['application/json'].schema, {
        $ref: '#/components/schemas/MobileProjectJobCreateRequest',
    });

    assert.deepEqual(operation.responses['200'].content['application/json'], {
        schema: { $ref: '#/components/schemas/MobileProjectJobCreateResponse' },
        example: { data: true },
    });
    assert.deepEqual(operation.responses['400'].content['application/json'].example, {
        success: false,
        message: ["'id' is required!"],
        error_code: 400,
    });
    assert.deepEqual(operation.responses['401'].content['application/json'].example, {
        message: 'Unauthenticated.',
    });
    assert.deepEqual(operation.responses['401'].content['application/json'].schema, {
        $ref: '#/components/schemas/MobileProjectJobUnauthenticatedError',
    });
    assert.deepEqual(operation.responses['403'].content['application/json'].example, {
        success: false,
        message: ['Project not found!'],
        error_code: 403,
    });
    assert.deepEqual(Object.keys(operation.responses['500'].content['application/json'].examples), [
        'notEligible',
        'alreadyMember',
    ]);
    assert.deepEqual(operation.responses['500'].content['application/json'].examples.notEligible.value, {
        success: false,
        message: ['This project is not eligible.'],
        error_code: 500,
    });
    assert.deepEqual(operation.responses['500'].content['application/json'].examples.alreadyMember.value, {
        success: false,
        message: ['You are a member of this project'],
        error_code: 500,
    });
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'], {
        schema: { $ref: '#/components/schemas/PublicRateLimitError' },
        example: { success: false, message: ['Too many requests.'], error_code: 429 },
    });

    assert.deepEqual(schemas.MobileProjectJobCreateResponse, {
        type: 'object',
        additionalProperties: false,
        required: ['data'],
        properties: { data: { const: true } },
    });
    assert.deepEqual(schemas.MobileProjectJobValidationError.properties.message.items, {
        const: "'id' is required!",
    });
    assert.deepEqual(schemas.MobileProjectJobProjectNotFoundError.properties.message.items, {
        const: 'Project not found!',
    });
    assert.deepEqual(schemas.MobileProjectJobDomainError.properties.message.items.oneOf, [
        { const: 'This project is not eligible.' },
        { const: 'You are a member of this project' },
    ]);
});

test('keeps request and response examples synthetic', () => {
    const examples = operation.requestBody.content['application/json'].examples;

    assert.deepEqual(Object.keys(examples), [
        'nestedId',
        'topLevelFallback',
        'nestedNullWins',
        'numericStringCast',
    ]);
    assert.deepEqual(examples.nestedId.value, { options: { parameters: { id: 7001 } } });
    assert.deepEqual(examples.topLevelFallback.value, { id: '7002' });
    assert.deepEqual(examples.nestedNullWins.value, {
        id: 7002,
        options: { parameters: { id: null } },
    });
    assert.deepEqual(examples.numericStringCast.value, {
        options: { parameters: { id: '7003.9' } },
    });
    for (const example of Object.values(operation.responses['500'].content['application/json'].examples)) {
        assert.match(example.value.message[0], /^This project|^You are a member/);
    }
});

test('guards repository-local implementation evidence, PHP coverage, generated docs, and package registration', async () => {
    const [routes, controller, action, exception, limiter, compatibility, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Projects/CreateMobileProjectJobController.php'),
        readText('app/Domain/Projects/Actions/CreateMobileProjectJob.php'),
        readText('app/Domain/Projects/Actions/MobileProjectJobException.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('tests/Feature/Legacy/MobileProjectJobsCompatibilityTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const packageJson = JSON.parse(packageSource);
    const versionedRoute = routes.match(/Route::post\('projects\/jobs', CreateMobileProjectJobController::class\)[\s\S]*?->name\('projects\.jobs\.create'\);/);

    assert.ok(versionedRoute);
    assert.match(versionedRoute[0], /->middleware\('mobile\.auth'\)/);
    assert.doesNotMatch(versionedRoute[0], /throttle/);
    assert.match(controller, /data_get\(\$request->all\(\), 'options\.parameters\.id', \$request->input\('id'\)\)/);
    assert.match(controller, /is_numeric\(\$projectId\)/);
    assert.match(controller, /\(int\) \$projectId <= 0/);
    assert.match(controller, /'message' => 'Unauthenticated\.'/);
    assert.match(action, /->transaction\(/);
    assert.match(action, /->lockForUpdate\(\)/);
    assert.match(action, /->whereNull\('deleted_at'\)/);
    assert.match(action, /->exists\(\)/);
    assert.match(action, /->insert\(\[/);
    assert.doesNotMatch(action, /dispatch|queue|ShouldQueue/);
    assert.match(exception, /This project is not eligible\./);
    assert.match(exception, /You are a member of this project/);
    assert.match(limiter, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(limiter, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(limiter, /'X-RateLimit-Remaining' => '0'/);

    assert.match(compatibility, /test_versioned_mobile_create_job_preserves_nested_precedence_and_php_numeric_coercion/);
    assert.match(compatibility, /test_versioned_mobile_create_job_requires_a_positive_cast_project_id/);
    assert.match(compatibility, /test_versioned_mobile_create_job_ignores_soft_deleted_project_and_job_rows/);
    assert.match(compatibility, /test_versioned_mobile_create_job_duplicate_returns_500_without_a_second_active_row/);
    assert.match(compatibility, /test_versioned_mobile_create_job_optional_global_rate_limit_preserves_exact_envelope_and_headers/);
    assert.match(compatibility, /test_versioned_mobile_create_job_does_not_emit_an_etag_for_conditional_requests/);

    assert.match(packageJson.scripts['test:mobile-project-application-contract'], /scripts\/docs\/mobile-project-application-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/mobile-project-application-contract\.test\.mjs/);
    assert.match(generatedHtml, /createMobileProjectJob/);
    assert.doesNotMatch(generatedHtml, /(?:\.\.\/|backend-mobile-[^" ]*\.php)/i);
});
