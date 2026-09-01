import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/inventory/sprites-2x']?.get;
const schemas = specification.components?.schemas ?? {};
const spriteFields = ['x', 'y', 'height', 'width', 'visible', 'pixelRatio'];
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

const assertSpriteMap = (sprites) => {
    assert.equal(typeof sprites, 'object');
    assert.notEqual(sprites, null);
    assert.equal(Array.isArray(sprites), false);

    for (const metadata of Object.values(sprites)) {
        assert.equal(typeof metadata, 'object');
        assert.notEqual(metadata, null);
        assert.equal(Array.isArray(metadata), false);
        assert.deepEqual(Object.keys(metadata).sort(), [...spriteFields].sort());
        assert.equal(Number.isInteger(metadata.x), true);
        assert.ok(metadata.x >= 0);
        assert.equal(Number.isInteger(metadata.y), true);
        assert.ok(metadata.y >= 0);
        assert.equal(Number.isInteger(metadata.height), true);
        assert.ok(metadata.height >= 1);
        assert.equal(Number.isInteger(metadata.width), true);
        assert.ok(metadata.width >= 1);
        assert.equal(typeof metadata.visible, 'boolean');
        assert.equal(Number.isInteger(metadata.pixelRatio), true);
        assert.ok(metadata.pixelRatio >= 1);
    }
};

test('documents the unauthenticated retina inventory sprite alias', () => {
    assert.ok(operation, 'GET /api/v1/inventory/sprites-2x must be documented');
    assert.equal(operation.operationId, 'getInventorySprites2x');
    assert.deepEqual(operation.tags, ['Inventory']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters ?? [], []);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/get-sprites2x`/);
    assert.match(operation.description, /no request parameters/);
    assert.match(operation.description, /top-level JSON object is keyed dynamically by sprite code/);
    assert.match(operation.description, /each value contains exactly `x`, `y`, `height`, `width`, `visible`, and `pixelRatio`/);
    assert.match(operation.description, /checked-in\/readable `public\/sprites\/sprites@2x\.json` asset metadata/);
    assert.match(operation.description, /Missing-file and malformed-JSON exceptional behavior is outside this contract/);
    assert.match(operation.description, /no endpoint authentication, endpoint-specific response cache, or ETag/);
    assert.match(operation.responses['429'].description, /Enforcement and limits are deployment-configurable/);
    assert.match(operation.responses['429'].description, /production enforcement is not asserted/);
});

test('reuses the exact six-field dynamic sprite map schemas', () => {
    assert.deepEqual(schemas.InventorySpriteMetadata.required.sort(), [...spriteFields].sort());
    assert.equal(schemas.InventorySpriteMetadata.additionalProperties, false);
    assert.deepEqual(Object.keys(schemas.InventorySpriteMetadata.properties).sort(), [...spriteFields].sort());
    assert.deepEqual(schemas.InventorySpriteMetadata.properties.x, { type: 'integer', minimum: 0 });
    assert.deepEqual(schemas.InventorySpriteMetadata.properties.y, { type: 'integer', minimum: 0 });
    assert.deepEqual(schemas.InventorySpriteMetadata.properties.height, { type: 'integer', minimum: 1 });
    assert.deepEqual(schemas.InventorySpriteMetadata.properties.width, { type: 'integer', minimum: 1 });
    assert.deepEqual(schemas.InventorySpriteMetadata.properties.visible, { type: 'boolean' });
    assert.deepEqual(schemas.InventorySpriteMetadata.properties.pixelRatio, { type: 'integer', minimum: 1 });
    assert.deepEqual(operation.responses['200'].content['application/json'].schema, {
        $ref: '#/components/schemas/InventorySpritesResponse',
    });
    assert.deepEqual(schemas.InventorySpritesResponse, {
        type: 'object',
        additionalProperties: { $ref: '#/components/schemas/InventorySpriteMetadata' },
    });
});

test('keeps synthetic 200 and optional global rate-limit examples valid', () => {
    const response = operation.responses['200'].content['application/json'];
    assert.ok(Object.keys(response.examples).length > 0);
    for (const example of Object.values(response.examples)) assertSpriteMap(example.value);

    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].schema, {
        $ref: '#/components/schemas/PublicRateLimitError',
    });
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('guards retina route, controller, asset, legacy parity, PHP, and package registration drift', async () => {
    const [assetSource, routes, controller, packageSource, compatibility] = await Promise.all([
        readText('public/sprites/sprites@2x.json'),
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Inventory/SpriteController.php'),
        readText('package.json'),
        readText('tests/Feature/Legacy/TypeMetadataCompatibilityTest.php'),
    ]);
    const asset = JSON.parse(assetSource);
    const packageJson = JSON.parse(packageSource);

    assertSpriteMap(asset);
    assert.match(routes, /Route::get\('get-sprites2x', \[SpriteController::class, 'retina'\]\)/);
    const versionedRoute = routes.match(/Route::get\('inventory\/sprites-2x', \[SpriteController::class, 'retina'\]\)[\s\S]*?->name\('inventory\.sprites-2x'\);/);
    assert.ok(versionedRoute);
    assert.doesNotMatch(versionedRoute[0], /middleware|auth/i);
    assert.match(controller, /public function retina\(\): JsonResponse/);
    assert.match(controller, /return response\(\)->json\(\$this->sprites\('sprites@2x\.json'\)\)/);
    assert.match(controller, /public_path\('sprites\/'\.\$file\)/);
    assert.match(controller, /JSON_THROW_ON_ERROR/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/inventory-retina-sprites-contract\.test\.mjs/);
    assert.match(compatibility, /test_retina_sprite_metadata_has_exact_legacy_shape_and_scalar_constraints/);
    assert.match(compatibility, /\$legacyRetina = \$this->getJson\('\/api\/get-sprites2x'\)/);
    assert.match(compatibility, /\$versionedRetina = \$this->getJson\('\/api\/v1\/inventory\/sprites-2x'\)/);
    assert.match(compatibility, /\$this->assertSame\(\$legacyRetina, \$versionedRetina\)/);
});
