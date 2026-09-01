import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/imagery/reports']?.post;
const schemas = specification.components?.schemas ?? {};

test('documents the anonymous synchronous mobile imagery report alias', () => {
    assert.ok(operation, 'POST /api/v1/imagery/reports must be documented');
    assert.equal(operation.operationId, 'createImageryReport');
    assert.deepEqual(operation.tags, ['Imagery reports']);
    assert.deepEqual(operation.security, []);
    assert.equal(operation.requestBody.required, false);
    assert.deepEqual(Object.keys(operation.responses), ['200', '400', '429']);
    assert.equal(operation.responses['401'], undefined);
    assert.equal(operation.responses['500'], undefined);
    assert.match(operation.description, /Synchronous alias of `POST \/api\/image-report`/);
    assert.match(operation.description, /active mobile callers use it/);
    assert.match(operation.description, /existing nested `options\.parameters\.<key>` value wins.*including null/s);
    assert.match(operation.description, /PHP `is_numeric`, cast to integer.*greater than zero/s);
    assert.match(operation.description, /numeric strings and JSON numeric values/);
    assert.match(operation.description, /trimmed value is nonblank/);
    assert.match(operation.description, /multibyte character maximum.*defaults to 2000/s);
    assert.match(operation.description, /soft-deleted rows/);
    assert.match(operation.description, /no queue, cache, ETag, endpoint idempotency promise, or duplicate suppression/);
    assert.match(operation.description, /`imagery-reports\|IP` throttle.*1 through 1000.*defaults to 10/s);
    assert.match(operation.description, /Generic database, authentication lookup, insert.*not stabilized/s);
});

test('locks exact request, success, validation, and dedicated throttle shapes', () => {
    assert.deepEqual(operation.requestBody.content['application/json'].schema, {
        $ref: '#/components/schemas/ImageryReportRequest',
    });
    assert.deepEqual(Object.keys(operation.requestBody.content['application/json'].examples), [
        'nestedParameters',
        'topLevelFallback',
        'nestedNullWins',
        'numericJsonValue',
        'trimmedMultibyteMessage',
    ]);
    assert.deepEqual(schemas.ImageryReportImageryId.type, ['integer', 'number', 'string', 'boolean', 'array', 'object', 'null']);
    assert.match(schemas.ImageryReportImageryId.description, /Every JSON value type is representable/);
    assert.match(schemas.ImageryReportImageryId.description, /numeric strings and JSON numbers/);
    assert.deepEqual(schemas.ImageryReportMessage.type, ['string', 'number', 'boolean', 'array', 'object', 'null']);
    assert.match(schemas.ImageryReportMessage.description, /Every JSON value type is representable/);
    assert.match(schemas.ImageryReportMessage.description, /Only a string is accepted/);
    assert.equal(schemas.ImageryReportRequest.additionalProperties, true);
    assert.deepEqual(schemas.ImageryReportRequest.properties.options.type, [
        'object', 'array', 'string', 'number', 'boolean', 'null',
    ]);
    assert.match(schemas.ImageryReportRequest.properties.options.description, /any JSON value/);
    assert.deepEqual(schemas.ImageryReportParameters.type, [
        'object', 'array', 'string', 'number', 'boolean', 'null',
    ]);
    assert.match(schemas.ImageryReportParameters.description, /any JSON value/);
    assert.deepEqual(schemas.ImageryReportRequest.properties.options.properties.parameters, {
        $ref: '#/components/schemas/ImageryReportParameters',
    });
    assert.deepEqual(schemas.ImageryReportSuccessResponse, {
        type: 'object',
        additionalProperties: false,
        required: ['data'],
        properties: { data: { $ref: '#/components/schemas/ImageryReportRow' } },
    });
    assert.deepEqual(schemas.ImageryReportRow.required, [
        'id',
        'sort_order',
        'created_at',
        'created_by_id',
        'updated_at',
        'updated_by_id',
        'deleted_at',
        'imagery_id',
        'description',
    ]);
    assert.deepEqual(operation.responses['200'].content['application/json'].example, {
        data: {
            id: 9001,
            sort_order: null,
            created_at: '2026-07-01T12:00:00.000000Z',
            created_by_id: 10,
            updated_at: '2026-07-01T12:00:00.000000Z',
            updated_by_id: 10,
            deleted_at: null,
            imagery_id: 123,
            description: 'Lane marking is obscured.',
        },
    });
    assert.deepEqual(Object.keys(operation.responses['400'].content['application/json'].examples), [
        'missingImageryId',
        'missingMessage',
        'messageTooLong',
        'imageryDoesNotExist',
    ]);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many reports. Please try again later.'],
        error_code: 429,
    });
    assert.deepEqual(Object.keys(operation.responses['429'].headers), [
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ]);
    assert.deepEqual(schemas.ImageryReportValidationError.properties.message.items.oneOf, [
        { const: "'imagery_id' is required!" },
        { const: "'message' is required!" },
        { type: 'string', pattern: "^'message' accepts at most [1-9][0-9]* characters!$" },
        { const: "'imagery_id' does not exist!" },
    ]);
    assert.equal(
        operation.responses['400'].content['application/json'].examples.messageTooLong.value.message[0],
        "'message' accepts at most 2000 characters!",
    );
    assert.equal(schemas.ImageryReportRateLimitError.properties.message.items.const, 'Too many reports. Please try again later.');
});

test('keeps examples synthetic and protects repository-local implementation and docs drift', async () => {
    const [routes, controller, action, provider, config, compatibility, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Imagery/ImageReportController.php'),
        readText('app/Domain/ImageryReports/Actions/CreateImageReport.php'),
        readText('app/Providers/AppServiceProvider.php'),
        readText('config/mapilio.php'),
        readText('tests/Feature/Legacy/ImageReportCompatibilityTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const packageJson = JSON.parse(packageSource);
    const examples = operation.requestBody.content['application/json'].examples;

    assert.deepEqual(examples.nestedParameters.value.options.parameters, {
        imagery_id: 123,
        message: '  Lane marking is obscured.  ',
    });
    assert.deepEqual(examples.nestedNullWins.value.options.parameters, { message: null });
    assert.equal(examples.topLevelFallback.value.imagery_id, '456.9');
    assert.equal(examples.numericJsonValue.value.imagery_id, 123.9);
    assert.doesNotMatch(JSON.stringify(examples), /access_token|refresh_token|secret|password/i);

    assert.match(routes, /Route::post\('image-report', ImageReportController::class\)\s*->middleware\('throttle:imagery-reports'\)\s*->name\('api\.legacy\.imagery\.reports'\);/);
    assert.match(routes, /Route::post\('imagery\/reports', ImageReportController::class\)\s*->middleware\('throttle:imagery-reports'\)\s*->name\('imagery\.reports'\);/);
    assert.match(controller, /data_get\(\$request->all\(\), "options\.parameters\.\{\$key\}", \$request->input\(\$key\)\)/);
    assert.match(controller, /is_numeric\(\$imageryId\)/);
    assert.match(controller, /\(int\) \$imageryId <= 0/);
    assert.match(controller, /is_string\(\$message\)/);
    assert.match(controller, /mb_strlen\(\$message\)/);
    assert.match(controller, /userFromBearer\(\$request->header\('Authorization'\)\)/);
    assert.match(action, /->where\('id', \$imageryId\)\s*->exists\(\)/);
    assert.doesNotMatch(action, /whereNull\('deleted_at'\)/);
    assert.match(action, /insertGetId\(/);
    assert.doesNotMatch(action, /dispatch|ShouldQueue|Cache|ETag/i);
    assert.match(provider, /RateLimiter::for\('imagery-reports'/);
    assert.match(provider, /->by\('imagery-reports\|'\.\$request->ip\(\)\)/);
    assert.match(provider, /min\(1000, max\(1, \$limit\)\)/);
    assert.match(provider, /Too many reports\. Please try again later\./);
    assert.match(config, /MAPILIO_IMAGERY_REPORT_MAX_MESSAGE_LENGTH', 2000/);
    assert.match(config, /MAPILIO_IMAGERY_REPORT_RATE_LIMIT', 10/);

    for (const testName of [
        'test_legacy_and_versioned_aliases_return_the_same_success_shape',
        'test_nested_null_values_win_over_top_level_fallbacks',
        'test_top_level_values_fall_back_when_options_or_parameters_are_non_objects',
        'test_imagery_id_preserves_numeric_string_and_json_number_casts',
        'test_message_is_trimmed_and_counted_by_multibyte_characters_at_the_boundary',
        'test_missing_malformed_expired_inactive_deleted_and_revoked_bearers_remain_anonymous',
        'test_valid_active_bearer_attributes_both_report_audit_columns',
        'test_soft_deleted_imagery_is_still_reportable',
        'test_dedicated_rate_limit_returns_exact_body_and_headers',
    ]) {
        assert.match(compatibility, new RegExp(`function ${testName}`));
    }
    assert.match(packageJson.scripts['test:mobile-imagery-report-contract'], /node --test scripts\/docs\/mobile-imagery-report-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/mobile-imagery-report-contract\.test\.mjs/);
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
