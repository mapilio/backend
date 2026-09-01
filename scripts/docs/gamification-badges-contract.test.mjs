import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/gamification/badges/{userId}']?.get;
const schemas = specification.components?.schemas ?? {};
const badgeFields = [
    'id', 'sort_order', 'created_at', 'created_by_id', 'updated_at', 'updated_by_id',
    'slug', 'image_id', 'available_level', 'is_custom', 'color_code', 'disabled_image_id',
    'enable', 'icon', 'point', 'title', 'info',
];
const fileFields = [
    'id', 'sort_order', 'created_at', 'created_by_id', 'updated_at', 'updated_by_id',
    'deleted_at', 'name', 'disk_id', 'folder_id', 'extension', 'size', 'mime_type',
    'entry_id', 'entry_type', 'keywords', 'height', 'width', 'alt_text', 'title',
    'caption', 'description', 'str_id', 'disk', 'folder', 'entry', 'path', 'location',
];
const diskFields = [
    'id', 'sort_order', 'created_at', 'created_by_id', 'updated_at', 'updated_by_id',
    'deleted_at', 'slug', 'adapter', 'name', 'description',
];
const folderFields = [
    'id', 'sort_order', 'created_at', 'created_by_id', 'updated_at', 'updated_by_id',
    'deleted_at', 'disk_id', 'slug', 'allowed_types', 'str_id', 'name', 'description',
];
const nextBadgeFields = badgeFields.filter((field) => !['enable', 'point', 'disabled_image'].includes(field));
const timestampSchema = {
    type: ['string', 'null'],
    format: 'date-time',
    pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
};
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

test('documents only the unauthenticated numeric gamification badge alias', () => {
    assert.ok(operation, 'GET /api/v1/gamification/badges/{userId} must be documented');
    assert.equal(specification.paths['/api/gamification/badges/{userId}'], undefined);
    assert.equal(operation.operationId, 'getGamificationBadges');
    assert.deepEqual(operation.tags, ['Public content']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters, [
        {
            name: 'userId',
            in: 'path',
            required: true,
            description: 'Numeric legacy user identifier.',
            schema: { type: 'integer', format: 'int64', minimum: 0 },
            example: 10,
        },
        {
            name: 'locale',
            in: 'query',
            required: false,
            allowEmptyValue: true,
            description: 'Optional locale. The canonical wire form is one scalar string. PHP bracket syntax such as `locale[]=tr` is tolerated outside this canonical schema, parses as a non-string array, and falls back to `en`. Omission uses the application locale, an empty scalar falls back to `en`, and scalar strings (including unrecognized values) are passed through as-is.',
            schema: { type: 'string' },
            example: 'en',
        },
    ]);
    assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/gamification\/badges\/{userId}` with exact success parity/);
    assert.match(operation.description, /constrained only to numeric route values/);
    assert.match(operation.description, /empty value, array, or other non-string falls back to `en`/);
    assert.match(operation.description, /unrecognized values.*null translation fields/);
    assert.match(operation.description, /checks only the id.*deleted timestamp remains visible.*derived leaderboard lookup excludes deleted users.*point.*0.*percentage.*0/s);
    assert.match(operation.description, /unknown user returns HTTP 200 with the exact empty JSON array `\[\]`, not an object/);
    assert.match(operation.description, /no endpoint authentication, endpoint-specific throttle, response cache, ETag, or 304/);
    assert.match(operation.responses['429'].description, /deployment-configurable/);
});

test('locks the exact badge, nested file, and next schemas', () => {
    assert.deepEqual(schemas.GamificationBadge.required, badgeFields);
    assert.deepEqual(Object.keys(schemas.GamificationBadge.properties), [...badgeFields, 'disabled_image']);
    assert.deepEqual(schemas.GamificationNextBadge.required, nextBadgeFields);
    assert.deepEqual(Object.keys(schemas.GamificationNextBadge.properties), nextBadgeFields);
    assert.deepEqual(schemas.GamificationBadgeFile.required, fileFields);
    assert.deepEqual(Object.keys(schemas.GamificationBadgeFile.properties), fileFields);
    assert.deepEqual(schemas.GamificationBadgeDisk.required, diskFields);
    assert.deepEqual(Object.keys(schemas.GamificationBadgeDisk.properties), diskFields);
    assert.deepEqual(schemas.GamificationBadgeFolder.required, folderFields);
    assert.deepEqual(Object.keys(schemas.GamificationBadgeFolder.properties), folderFields);
    assert.deepEqual(schemas.GamificationBadge.properties.created_at, timestampSchema);
    assert.deepEqual(schemas.GamificationBadgeFile.properties.deleted_at, timestampSchema);
    assert.deepEqual(schemas.GamificationBadge.properties.point, { type: 'integer' });
    assert.deepEqual(schemas.GamificationBadge.properties.slug, { type: ['string', 'number', 'boolean'] });
    assert.deepEqual(schemas.GamificationBadge.properties.color_code, { type: ['string', 'number', 'boolean', 'null'] });
    for (const field of ['title', 'info']) {
        assert.deepEqual(schemas.GamificationBadge.properties[field], { type: ['string', 'number', 'boolean', 'null'] });
    }
    for (const field of ['entry_type', 'keywords', 'height', 'width', 'alt_text', 'title', 'caption', 'description']) {
        assert.deepEqual(schemas.GamificationBadgeFile.properties[field], { type: ['string', 'number', 'boolean', 'null'] });
    }
    for (const schema of [schemas.GamificationBadge, schemas.GamificationNextBadge]) {
        assert.deepEqual(schema.properties.title, { type: ['string', 'number', 'boolean', 'null'] });
        assert.deepEqual(schema.properties.info, { type: ['string', 'number', 'boolean', 'null'] });
        assert.deepEqual(schema.properties.color_code, { type: ['string', 'number', 'boolean', 'null'] });
    }
    for (const schema of [schemas.GamificationBadgeDisk, schemas.GamificationBadgeFolder]) {
        for (const field of ['name', 'description']) {
            assert.deepEqual(schema.properties[field], { type: ['string', 'null'] });
        }
        for (const field of ['created_at', 'updated_at', 'deleted_at']) {
            assert.deepEqual(schema.properties[field], timestampSchema);
        }
    }
    assert.deepEqual(schemas.GamificationBadgeFolder.properties.disk_id, { type: ['integer', 'null'] });
    for (const field of ['disk_id', 'folder_id', 'entry_id', 'size']) {
        assert.deepEqual(schemas.GamificationBadgeFile.properties[field], { type: ['integer', 'null'] });
    }
    assert.deepEqual(schemas.GamificationBadgeFolder.properties.allowed_types, { type: ['string', 'number', 'boolean', 'null'] });
    assert.equal(schemas.GamificationBadge.required.includes('disabled_image'), false);
    assert.deepEqual(schemas.GamificationBadge.properties.disabled_image, {
        oneOf: [
            { $ref: '#/components/schemas/GamificationBadgeFile' },
            { type: 'null' },
        ],
    });
    assert.deepEqual(schemas.GamificationBadgeFile.properties.disk, {
        oneOf: [
            { $ref: '#/components/schemas/GamificationBadgeDisk' },
            { type: 'null' },
        ],
    });
    assert.deepEqual(schemas.GamificationBadgeFile.properties.folder, {
        oneOf: [
            { $ref: '#/components/schemas/GamificationBadgeFolder' },
            { type: 'null' },
        ],
    });
    assert.deepEqual(schemas.GamificationBadgesResponse.required, ['badges', 'point', 'next']);
    assert.deepEqual(schemas.GamificationBadgesResponse.properties.point, { type: ['integer', 'string'] });
    assert.deepEqual(schemas.GamificationBadgesResponse.properties.next.properties.badge, {
        oneOf: [
            { $ref: '#/components/schemas/GamificationNextBadge' },
            { type: 'null' },
        ],
    });
    assert.deepEqual(schemas.GamificationBadgesResponse.properties.next.properties.percentage, { type: ['integer', 'string'] });
    assert.deepEqual(schemas.GamificationBadgesUnknownUserResponse, {
        type: 'array',
        maxItems: 0,
        items: false,
        description: 'Exact HTTP 200 empty JSON array returned when no user row exists.',
    });
    assert.deepEqual(operation.responses['200'].content['application/json'].schema.oneOf, [
        { $ref: '#/components/schemas/GamificationBadgesResponse' },
        { $ref: '#/components/schemas/GamificationBadgesUnknownUserResponse' },
    ]);
});

test('keeps synthetic populated and unknown-user examples exact', async () => {
    const response = operation.responses['200'].content['application/json'];
    const compatibility = await readText('tests/Feature/Legacy/GamificationBadgesCompatibilityTest.php');
    assert.deepEqual(Object.keys(response.examples), ['populated', 'empty']);
    const populated = response.examples.populated.value;
    assert.deepEqual(Object.keys(populated), ['badges', 'point', 'next']);
    assert.deepEqual(populated.badges.map((badge) => Object.keys(badge)), [
        badgeFields,
        [...badgeFields, 'disabled_image'],
    ]);
    assert.equal(populated.badges[0].enable, true);
    assert.equal(populated.badges[1].enable, false);
    assert.equal(typeof populated.badges[1].point, 'number');
    assert.equal(populated.badges[1].disabled_image.disk.slug, 'local');
    assert.equal(populated.badges[1].disabled_image.folder.slug, 'badges');
    assert.equal(populated.badges[1].disabled_image.entry, null);
    assert.deepEqual(populated.badges.map(({ id, slug, title }) => ({ id, slug, title })), [
        { id: 5, slug: 'street_stoller', title: 'Street Stroller' },
        { id: 6, slug: 'pathfinder', title: 'Pathfinder' },
    ]);
    assert.deepEqual(
        {
            file: populated.badges[1].disabled_image.str_id,
            path: populated.badges[1].disabled_image.path,
            location: populated.badges[1].disabled_image.location,
            point: populated.point,
            percentage: populated.next.percentage,
        },
        {
            file: 'disabled-file',
            path: 'badges/disabled.png',
            location: 'local://badges/disabled.png',
            point: '97',
            percentage: '97',
        },
    );
    assert.equal(populated.next.badge.id, 5);
    assert.equal(typeof populated.next.percentage, 'string');
    assert.deepEqual(response.examples.empty.value, []);
    for (const fixtureEvidence of [
        "'id' => 5",
        "'slug' => 'street_stoller'",
        "'title' => 'Street Stoller'",
        "'id' => 6",
        "'slug' => 'pathfinder'",
        "'title' => 'Pathfinder'",
        "'str_id' => 'disabled-file'",
        "'path' => 'badges/disabled.png'",
        "'location' => 'local://badges/disabled.png'",
        "'point' => '97'",
        "'percentage' => '97'",
    ]) {
        assert.match(compatibility, new RegExp(fixtureEvidence.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    }
    assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('guards route, source, focused PHP contract, and package registration against drift', async () => {
    const [routes, controller, query, compatibility, limiter, packageSource] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Gamification/GamificationBadgesController.php'),
        readText('app/Domain/Gamification/Queries/GamificationBadgesQuery.php'),
        readText('tests/Feature/Legacy/GamificationBadgesCompatibilityTest.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('package.json'),
    ]);
    const versionedRoute = routes.match(/Route::get\('gamification\/badges\/\{userId\}', GamificationBadgesController::class\)\s*->whereNumber\('userId'\)\s*->name\('gamification\.badges'\);/);
    const legacyRoute = routes.match(/Route::get\('gamification\/badges\/\{userId\}', GamificationBadgesController::class\)\s*->whereNumber\('userId'\)\s*->name\('api\.legacy\.gamification\.badges'\);/);
    assert.ok(versionedRoute);
    assert.ok(legacyRoute);
    assert.match(versionedRoute[0], /whereNumber\('userId'\)/);
    assert.doesNotMatch(versionedRoute[0], /middleware|throttle|cache/i);
    assert.match(controller, /response\(\)->json\(\$query->get/);
    assert.match(query, /@return array\{\}\|array\{/);
    assert.match(query, /return \[\];/);
    assert.match(query, /where\('id', \$userId\)\s*->exists\(\)/);
    assert.match(query, /is_string\(\$locale\) && \$locale !== '' \? \$locale : 'en'/);
    assert.match(compatibility, /test_versioned_gamification_badges_alias_returns_same_contract/);
    assert.match(compatibility, /test_gamification_badges_locale_uses_app_locale_and_falls_back_to_en_for_empty_or_non_string_values/);
    assert.match(compatibility, /test_gamification_badges_unrecognized_scalar_locale_is_passed_through_without_translation_fallback/);
    assert.match(compatibility, /test_gamification_badges_does_not_filter_deleted_users/);
    assert.match(compatibility, /assertSame\(0, \$payload\['point'\]\)/);
    assert.match(compatibility, /assertSame\('0', \$payload\['next'\]\['percentage'\]\)/);
    assert.match(compatibility, /test_gamification_badges_optional_global_rate_limit_preserves_legacy_error_envelope/);
    assert.match(limiter, /'message' => \['Too many requests\.'\]/);
    assert.match(limiter, /'Retry-After' => \(string\) \$retryAfter/);
    assert.match(limiter, /'X-RateLimit-Limit' => \(string\) \$maxAttempts/);
    assert.match(limiter, /'X-RateLimit-Remaining' => '0'/);

    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['test:gamification-badges-contract'], /scripts\/docs\/gamification-badges-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/gamification-badges-contract\.test\.mjs/);
});
