import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/imagery/uploads']?.post;
const schemas = specification.components?.schemas ?? {};

test('documents the authenticated metadata-only imagery upload operation', () => {
    assert.ok(operation, 'POST /api/v1/imagery/uploads must be documented');
    assert.equal(operation.operationId, 'createImageryUpload');
    assert.deepEqual(operation.tags, ['Imagery uploads']);
    assert.deepEqual(operation.security, [{ mobileBearer: [] }]);
    assert.deepEqual(Object.keys(operation.responses), ['200', '400', '401', '429']);
    assert.match(operation.description, /metadata ingestion, not image-byte upload/);
    assert.match(operation.description, /options\.parameters/);
    assert.match(operation.description, /whole-body fallback/);
    assert.match(operation.description, /body may validly contain both shapes/);
    assert.match(operation.description, /nested `options\.parameters` values take precedence/);
    assert.match(operation.description, /sequence_uuid.*precedence/s);
    assert.match(operation.description, /car_speed.*wins over.*carSpeed/s);
    assert.match(operation.description, /acceleration.*wins over.*accelerometer/s);
    assert.match(operation.description, /organization_key.*project_key.*null/s);
    assert.match(operation.description, /does not promise image transfer, anonymization, AI processing, publication, or downstream completion/);
    assert.match(operation.description, /no endpoint-specific throttle, response cache, or ETag/);
    assert.match(operation.description, /Uncaught framework, database, Carbon, JSON-encoding, array\/object, and exceptional cast failures/);
    assert.equal(operation.requestBody.required, true);
    assert.ok(operation.requestBody.content['application/json'].examples.wrapped.value.options.parameters);
    assert.ok(operation.requestBody.content['application/json'].examples.wholeBodyFallback.value.json_data);
    assert.equal(operation.responses['200'].content['application/json'].example.count, 1);
    assert.deepEqual(operation.responses['200'].content['application/json'].example, {
        status: true,
        data: true,
        sequence_uuid: 'synthetic-sequence-summary-150',
        count: 1,
    });
    assert.equal(operation.responses['200'].content['application/json'].schema.$ref, '#/components/schemas/ImageryUploadSuccessResponse');
    assert.equal(operation.responses['400'].content['application/json'].schema.$ref, '#/components/schemas/ImageryUploadValidationError');
    assert.equal(operation.responses['401'].content['application/json'].schema.$ref, '#/components/schemas/ImageryUploadUnauthenticatedError');
    assert.equal(operation.responses['429'].content['application/json'].schema.$ref, '#/components/schemas/PublicRateLimitError');
    assert.deepEqual(Object.keys(operation.responses['429'].headers), ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
    assert.deepEqual(operation.responses['400'].content['application/json'].examples.falseHash.value, {
        success: false,
        message: ["'summary.Information.hash' is required!"],
        error_code: 400,
    });
    assert.deepEqual(operation.responses['400'].content['application/json'].examples.emptyPointField.value, {
        success: false,
        message: ["'latitude' is required!"],
        error_code: 400,
    });
    assert.equal(operation.responses['200'].content['application/json'].schema.$ref, '#/components/schemas/ImageryUploadSuccessResponse');
    assert.equal(operation.responses['409'], undefined);
    assert.equal(operation.responses['500'], undefined);
});

test('locks imagery upload field requirements, nullable coercions, and exact stable envelopes', () => {
    assert.deepEqual(schemas.ImageryUploadRequest.anyOf, [
        { $ref: '#/components/schemas/ImageryUploadOptionsEnvelope' },
        { $ref: '#/components/schemas/ImageryUploadParameters' },
    ]);
    assert.equal(schemas.ImageryUploadRequest.oneOf, undefined);
    assert.match(schemas.ImageryUploadRequest.description, /both at once/);
    assert.match(schemas.ImageryUploadRequest.description, /nested `options\.parameters`/);
    assert.deepEqual(schemas.ImageryUploadParameters.required, ['json_data', 'summary']);
    assert.deepEqual(schemas.ImageryUploadSummary.required, ['Information']);
    assert.deepEqual(schemas.ImageryUploadSummaryInformation.required, ['hash']);
    assert.deepEqual(schemas.ImageryUploadRequiredPointScalar.anyOf, [
        { type: 'string', minLength: 1 },
        { type: 'number' },
        { type: 'boolean' },
    ]);
    assert.deepEqual(schemas.ImageryUploadNonEmptyStringCastScalar.anyOf, [
        { type: 'string', minLength: 1 },
        { type: 'number' },
        { const: true },
    ]);
    assert.equal(
        schemas.ImageryUploadSummaryInformation.properties.hash.$ref,
        '#/components/schemas/ImageryUploadNonEmptyStringCastScalar',
    );
    assert.deepEqual(schemas.ImageryUploadSummaryInformation.properties.sequence_uuid.anyOf, [
        { type: 'null' },
        { $ref: '#/components/schemas/ImageryUploadNonEmptyStringCastScalar' },
    ]);

    const requiredPointFields = [
        'latitude', 'longitude', 'heading', 'altitude', 'orientation', 'captureTime', 'filename',
        'deviceMake', 'deviceModel', 'imageSize', 'fov', 'sequenceUuid', 'anomaly', 'roll', 'pitch', 'yaw',
    ];
    const optionalPointFields = [
        'car_speed', 'carSpeed', 'vfov', 'focalLength', 'focalLength35', 'gyroscope', 'acceleration',
        'accelerometer', 'velocity', 'accuracy_level', 'capture_address', 'source', 'sourceUser',
    ];
    assert.deepEqual(schemas.ImageryUploadPoint.required, requiredPointFields);
    assert.deepEqual(Object.keys(schemas.ImageryUploadPoint.properties), [...requiredPointFields, ...optionalPointFields]);
    assert.equal(schemas.ImageryUploadPoint.additionalProperties, true);
    for (const field of requiredPointFields) {
        assert.equal(
            schemas.ImageryUploadPoint.properties[field].$ref,
            '#/components/schemas/ImageryUploadRequiredPointScalar',
        );
    }
    assert.equal(schemas.ImageryUploadPoint.properties.car_speed.type.includes('null'), true);
    assert.equal(schemas.ImageryUploadPoint.properties.acceleration.type.includes('null'), true);
    assert.match(schemas.ImageryUploadPoint.properties.car_speed.description, /wins over `carSpeed`/);
    assert.match(schemas.ImageryUploadPoint.properties.acceleration.description, /wins over `accelerometer`/);
    assert.match(schemas.ImageryUploadSummaryInformation.properties.sequence_uuid.description, /first point's `sequenceUuid`/);

    assert.deepEqual(schemas.ImageryUploadSuccessResponse.required, ['status', 'data', 'sequence_uuid', 'count']);
    assert.equal(schemas.ImageryUploadSuccessResponse.additionalProperties, false);
    assert.deepEqual(schemas.ImageryUploadValidationError.required, ['success', 'message', 'error_code']);
    assert.deepEqual(schemas.ImageryUploadUnauthenticatedError.properties.message, { const: 'Unauthenticated.' });
});

test('guards the route, controller, action, PHP compatibility assertions, and local docs build', async () => {
    const [routes, controller, action, compatibility, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Imagery/ImageryUploadController.php'),
        readText('app/Domain/ImageryUploads/Actions/CreateImageryUpload.php'),
        readText('tests/Feature/Legacy/ImageryUploadCompatibilityTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const packageJson = JSON.parse(packageSource);

    const versionedRoute = routes.match(/Route::post\('imagery\/uploads', ImageryUploadController::class\)[\s\S]*?->name\('imagery\.uploads'\);/);
    assert.ok(versionedRoute);
    assert.match(versionedRoute[0], /middleware\('mobile\.auth'\)/);
    assert.doesNotMatch(versionedRoute[0], /throttle/i);
    assert.match(controller, /data_get\(\$request->all\(\), 'options\.parameters', \$request->all\(\)\)/);
    assert.match(controller, /'message' => 'Unauthenticated\.'/);
    assert.match(action, /\$summary\['sequence_uuid'\] \?\? \$jsonData\[0\]\['sequenceUuid'\]/);
    assert.match(action, /\$point\['car_speed'\] \?\? \$point\['carSpeed'\]/);
    assert.match(action, /\$point\['acceleration'\] \?\? \$point\['accelerometer'\]/);
    assert.match(action, /\$organizationKey = \$this->blankToNull\(\$parameters\['organization_key'\] \?\? null\)/);
    assert.match(action, /\$projectKey = \$this->blankToNull\(\$parameters\['project_key'\] \?\? null\)/);
    assert.match(compatibility, /test_versioned_upload_accepts_whole_body_parameters_fallback_and_returns_exact_success_envelope/);
    assert.match(compatibility, /test_versioned_upload_prefers_nested_parameters_when_both_request_shapes_are_valid/);
    assert.match(compatibility, /test_versioned_upload_rejects_false_or_empty_hash_and_empty_required_point/);
    assert.match(compatibility, /test_upload_metadata_preserves_php_coercions_alias_precedence_and_blank_scope/);
    assert.match(packageJson.scripts['test:imagery-upload-contract'], /scripts\/docs\/imagery-upload-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/imagery-upload-contract\.test\.mjs/);
    assert.match(generatedHtml, /createImageryUpload/);
    assert.doesNotMatch(generatedHtml, /mapilio-mobile|sibling mobile/i);
});
