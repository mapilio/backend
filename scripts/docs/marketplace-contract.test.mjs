import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const operation = specification.paths?.['/api/v1/projects/marketplaces']?.get;
const schemas = specification.components?.schemas ?? {};
const propertyFields = [
    'marketplace_name',
    'marketplace_description',
    'id',
    'project_key',
    'organization_key',
    'created_at',
    'owner',
    'project_camera_type',
    'distance_km',
];

const parseGeojson = (response) => {
    assert.deepEqual(Object.keys(response), ['data']);
    assert.deepEqual(Object.keys(response.data), ['geojson']);
    assert.equal(typeof response.data.geojson, 'string');
    return JSON.parse(response.data.geojson);
};

const assertFeatureCollection = (geojson) => {
    assert.deepEqual(Object.keys(geojson), ['type', 'features']);
    assert.equal(geojson.type, 'FeatureCollection');
    assert.ok(geojson.features === null || Array.isArray(geojson.features));

    for (const feature of geojson.features ?? []) {
        assert.deepEqual(Object.keys(feature), ['type', 'properties', 'geometry']);
        assert.equal(feature.type, 'Feature');
        assert.deepEqual(Object.keys(feature.properties), propertyFields);
        assert.equal(Number.isInteger(feature.properties.id), true);

        for (const field of [
            'marketplace_name',
            'marketplace_description',
            'project_key',
            'organization_key',
            'created_at',
            'owner',
            'project_camera_type',
        ]) {
            assert.ok(feature.properties[field] === null || typeof feature.properties[field] === 'string');
        }

        assert.ok(feature.properties.distance_km === null || feature.properties.distance_km === '0' || typeof feature.properties.distance_km === 'number');
        assert.ok(feature.geometry === null || (typeof feature.geometry === 'object' && !Array.isArray(feature.geometry)));
    }
};

test('documents only the unauthenticated v1 marketplace alias and its legacy inputs', () => {
    assert.ok(operation, 'GET /api/v1/projects/marketplaces must be documented');
    assert.equal(specification.paths['/api/v1/projects/marketplaces'].post, undefined);
    assert.equal(operation.operationId, 'getProjectMarketplaces');
    assert.deepEqual(operation.tags, ['Public content']);
    assert.deepEqual(operation.security, []);
    assert.deepEqual(operation.parameters.map(({ name, in: location, required, allowEmptyValue }) => ({
        name,
        in: location,
        required,
        allowEmptyValue,
    })), [
        { name: 'lat', in: 'query', required: false, allowEmptyValue: true },
        { name: 'lon', in: 'query', required: false, allowEmptyValue: true },
    ]);
    assert.deepEqual(operation.parameters.map(({ schema }) => schema), [
        { type: 'string' },
        { type: 'string' },
    ]);
    assert.match(operation.description, /unauthenticated v1 alias of `GET \/api\/get-marketplaces`/);
    assert.match(operation.description, /exactly `\{data: \{geojson: string\}\}`/);
    assert.match(operation.description, /JSON-encoded string.*not an object/);
    assert.match(operation.description, /only when both values are filled/);
    assert.match(operation.description, /missing value, empty string, or exact query string `"0"`.*disables validation and sorting/s);
    assert.doesNotMatch(operation.description, /false-like|zero-like/);
    assert.match(operation.description, /distance_km.*string/);
    assert.match(operation.description, /string `"0"`/);
    assert.match(operation.description, /latitude.*\[-90, 90\].*longitude.*\[-180, 180\]/s);
    assert.match(operation.description, /Numeric-distance rows are ordered ascending when available/);
    assert.match(operation.description, /missing-distance value and its placement are database-dependent/);
    assert.match(operation.description, /PostgreSQL can emit null.*portable path can retain the string "0"/s);
    assert.match(operation.description, /default PostgreSQL row ordering is not guaranteed/i);
    assert.match(operation.description, /No pagination metadata, response cache, or ETag/);
    assert.match(operation.description, /optional global API limiter.*deployment-configurable/s);
    assert.match(operation.responses['429'].description, /Enforcement and limits are deployment-configurable/);
    assert.match(operation.responses['429'].description, /response body wording is stable/);
    assert.doesNotMatch(operation.responses['429'].description, /wording.*configurable/);
    assert.deepEqual(Object.keys(operation.responses), ['200', '400', '429']);
});

test('locks the marketplace envelope, nested feature fields, and scalar/nullability schemas', () => {
    assert.deepEqual(schemas.MarketplaceResponse, {
        type: 'object',
        additionalProperties: false,
        required: ['data'],
        properties: {
            data: {
                type: 'object',
                additionalProperties: false,
                required: ['geojson'],
                properties: {
                    geojson: {
                        type: 'string',
                        description: 'A JSON-encoded GeoJSON FeatureCollection string, not a nested JSON object.',
                        contentMediaType: 'application/json',
                        contentSchema: { $ref: '#/components/schemas/MarketplaceFeatureCollection' },
                    },
                },
            },
        },
    });
    assert.deepEqual(schemas.MarketplaceFeatureCollection.required, ['type', 'features']);
    assert.deepEqual(Object.keys(schemas.MarketplaceFeatureCollection.properties), ['type', 'features']);
    assert.deepEqual(schemas.MarketplaceFeatureCollection.properties.features.type, ['array', 'null']);
    assert.deepEqual(schemas.MarketplaceFeature.required, ['type', 'properties', 'geometry']);
    assert.deepEqual(Object.keys(schemas.MarketplaceFeature.properties), ['type', 'properties', 'geometry']);
    assert.deepEqual(schemas.MarketplaceProperties.required, propertyFields);
    assert.deepEqual(Object.keys(schemas.MarketplaceProperties.properties), propertyFields);

    for (const field of [
        'marketplace_name',
        'marketplace_description',
        'project_key',
        'organization_key',
        'created_at',
        'owner',
        'project_camera_type',
    ]) {
        assert.deepEqual(schemas.MarketplaceProperties.properties[field].type, ['string', 'null']);
    }
    assert.deepEqual(schemas.MarketplaceProperties.properties.id, { type: 'integer', format: 'int64' });
    assert.deepEqual(schemas.MarketplaceProperties.properties.distance_km.oneOf, [
        { const: '0' },
        { type: 'number' },
        { type: 'null' },
    ]);
    assert.deepEqual(schemas.MarketplaceGeometry.type, ['object', 'null']);
});

test('parses every synthetic geojson example and checks default, coordinate, and empty variants', () => {
    const success = operation.responses['200'].content['application/json'];
    assert.deepEqual(Object.keys(success.examples), ['default', 'coordinates', 'empty']);

    const decoded = Object.fromEntries(Object.entries(success.examples).map(([name, example]) => [
        name,
        parseGeojson(example.value),
    ]));
    for (const geojson of Object.values(decoded)) assertFeatureCollection(geojson);

    assert.ok(decoded.default.features.length > 0);
    assert.ok(decoded.default.features.every(({ properties }) => properties.distance_km === '0'));
    assert.deepEqual(
        decoded.coordinates.features.map(({ properties }) => properties.distance_km),
        [12.5, null],
    );
    assert.equal(decoded.coordinates.features[0].properties.marketplace_description, null);
    assert.equal(decoded.coordinates.features[0].geometry.type, 'Polygon');
    assert.equal(decoded.coordinates.features[1].geometry, null);
    assert.equal(decoded.coordinates.features[1].properties.project_key, null);
    assert.deepEqual(decoded.empty, { type: 'FeatureCollection', features: null });

    assert.deepEqual(operation.responses['400'].content['application/json'].examples['non-numeric'].value, {
        success: false,
        message: ["'lat' and 'lon' must be numeric coordinates."],
        error_code: 400,
    });
    assert.deepEqual(operation.responses['400'].content['application/json'].examples['out-of-range'].value, {
        success: false,
        message: ["'lat' and 'lon' must be valid coordinates."],
        error_code: 400,
    });
});

test('keeps the optional global rate-limit error contract exact', () => {
    assert.deepEqual(operation.responses['429'].headers, {
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
    });
    assert.deepEqual(operation.responses['429'].content['application/json'].example, {
        success: false,
        message: ['Too many requests.'],
        error_code: 429,
    });
});

test('guards documented marketplace claims against route, source, limiter, and PHP test drift', async () => {
    const [routes, controller, query, compatibility, rateLimiter] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/Projects/MarketplaceController.php'),
        readText('app/Domain/Projects/Queries/MarketplaceQuery.php'),
        readText('tests/Feature/Legacy/MarketplaceCompatibilityTest.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
    ]);

    assert.match(routes, /Route::get\('get-marketplaces', MarketplaceController::class\)/);
    const versionedRoute = routes.match(/Route::get\('projects\/marketplaces',[\s\S]*?->name\('projects\.marketplaces'\);/);
    assert.ok(versionedRoute);
    assert.doesNotMatch(versionedRoute[0], /middleware|auth/i);

    assert.match(controller, /\$coordinates = \$this->coordinates\(\$request\)/);
    assert.match(controller, /\$query->geojson\(\$coordinates\['lat'\], \$coordinates\['lon'\]\)/);
    assert.match(controller, /if \(! \$this->legacyFilled\(\$lat\) \|\| ! \$this->legacyFilled\(\$lon\)\)/);
    assert.match(controller, /is_numeric\(\$lat\).*is_numeric\(\$lon\)/s);
    assert.match(controller, /\$lat < -90 \|\| \$lat > 90 \|\| \$lon < -180 \|\| \$lon > 180/);
    assert.match(controller, /must be numeric coordinates/);
    assert.match(controller, /must be valid coordinates/);

    assert.match(query, /if \(\$lat !== null && \$lon !== null\)/);
    assert.match(query, /ORDER BY DISTANCE_KM ASC/);
    assert.match(query, /ST_DISTANCE\(SHAPE\.POLYGON,ST_SETSRID\(ST_MAKEPOINT\(\?,\?\),4326\)\)/);
    assert.doesNotMatch(query, /COALESCE\s*\(\s*ST_DISTANCE/i);
    assert.match(query, /\$rows = \$rows->sortBy\('distance_km'\)->values\(\)/);
    assert.match(query, /\$distance = '0'/);
    assert.match(query, /'features' => \$features === \[\] \? null : \$features/);
    for (const field of propertyFields.slice(0, -1)) assert.match(query, new RegExp(`'${field}' =>`));
    assert.match(query, /'distance_km' => \$row\['distance_km'\]/);
    assert.match(query, /JSON_INVALID_UTF8_SUBSTITUTE/);
    assert.match(compatibility, /assertSame\('sqlite', Schema::getConnection\(\)->getDriverName\(\)\)/);

    for (const testName of [
        'test_versioned_marketplaces_preserves_exact_default_geojson_string_contract',
        'test_versioned_marketplaces_preserves_nullable_fields_and_null_geometry',
        'test_versioned_marketplaces_coordinates_match_legacy_order_and_types',
        'test_versioned_marketplaces_inactive_or_partial_coordinate_strings_match_legacy',
        'test_versioned_marketplaces_decimal_zero_strings_remain_active',
        'test_versioned_marketplaces_exact_centroid_zero_distance_is_numeric',
        'test_versioned_marketplaces_active_coordinates_with_missing_geometry_match_legacy',
        'test_versioned_marketplaces_coordinate_400_envelopes_match_legacy',
        'test_versioned_marketplaces_malformed_created_timestamps_match_legacy',
        'test_versioned_marketplaces_substitute_invalid_utf8_like_legacy',
        'test_versioned_marketplaces_empty_features_are_null',
    ]) {
        assert.match(compatibility, new RegExp(`public function ${testName}\\(`));
    }

    assert.match(rateLimiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(rateLimiter, /'message' => \['Too many requests\.'\]/);
    assert.match(rateLimiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
});
