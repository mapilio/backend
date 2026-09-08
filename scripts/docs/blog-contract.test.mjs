import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const readText = (relativePath) => readFile(resolve(repositoryRoot, relativePath), 'utf8');
const specification = JSON.parse(await readText('docs/api/openapi-v1.json'));
const listOperation = specification.paths?.['/api/v1/content/blogs']?.get;
const detailOperation = specification.paths?.['/api/v1/content/blogs/{slug}']?.get;
const v2ListOperation = specification.paths?.['/api/v2/content/blogs']?.get;
const v2DetailOperation = specification.paths?.['/api/v2/content/blogs/{slug}']?.get;
const schemas = specification.components?.schemas ?? {};
const listFields = [
    'id', 'sort_order', 'created_at', 'created_by_id', 'updated_at', 'updated_by_id', 'deleted_at',
    'str_id', 'type_id', 'publish_at', 'author_id', 'entry_id', 'entry_type', 'category_id', 'featured',
    'enabled', 'tags', 'blog_cover_photo', 'category_name', 'other_authors', 'author_detail', 'title',
    'summary', 'slug', 'meta_title', 'meta_description',
];
const detailFields = [
    'id', 'sort_order', 'created_at', 'created_by_id', 'updated_at', 'updated_by_id', 'deleted_at',
    'str_id', 'type_id', 'publish_at', 'author_id', 'entry_id', 'entry_type', 'category_id', 'featured',
    'enabled', 'tags', 'blog_cover_photo', 'locale', 'title', 'summary', 'meta_title', 'meta_description',
    'slug', 'category_name', 'content', 'other_authors', 'author_detail',
];
const paginationFields = [
    'current_page', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url',
    'path', 'per_page', 'prev_page_url', 'to', 'total',
];
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
        description: 'Requests remaining in the current limiter window; the rejected response serializes this as `0`.',
        schema: { const: '0' },
    },
};

test('documents only the unauthenticated versioned blog GETs', () => {
    assert.ok(listOperation, 'GET /api/v1/content/blogs must be documented');
    assert.ok(detailOperation, 'GET /api/v1/content/blogs/{slug} must be documented');
    assert.ok(v2ListOperation, 'GET /api/v2/content/blogs must be documented');
    assert.ok(v2DetailOperation, 'GET /api/v2/content/blogs/{slug} must be documented');
    assert.equal(specification.paths['/api/get-blogs'], undefined);
    assert.equal(specification.paths['/api/get-blog-detail/{slug}'], undefined);

    for (const [operation, operationId, parameters] of [
        [listOperation, 'getBlogPosts', [
            { name: 'page', in: 'query', required: false },
            { name: 'locale', in: 'query', required: false },
            { name: 'category-prefix', in: 'query', required: false },
            { name: 'category', in: 'query', required: false },
        ]],
        [detailOperation, 'getBlogDetail', [
            { name: 'slug', in: 'path', required: true },
            { name: 'locale', in: 'query', required: false },
        ]],
        [v2ListOperation, 'getBlogPostsV2', [
            { name: 'page', in: 'query', required: false },
            { name: 'locale', in: 'query', required: false },
            { name: 'category-prefix', in: 'query', required: false },
            { name: 'category', in: 'query', required: false },
        ]],
        [v2DetailOperation, 'getBlogDetailV2', [
            { name: 'slug', in: 'path', required: true },
            { name: 'locale', in: 'query', required: false },
        ]],
    ]) {
        assert.equal(operation.operationId, operationId);
        assert.deepEqual(operation.tags, ['Public content']);
        assert.deepEqual(operation.security, []);
        assert.deepEqual(operation.parameters.map(({ name, in: location, required }) => ({ name, in: location, required })), parameters);
        assert.deepEqual(Object.keys(operation.responses), ['200', '429']);
        assert.equal(operation.responses['401'], undefined);
        assert.equal(operation.responses['304'], undefined);
        assert.match(operation.description, /no endpoint-specific throttle, response cache, ETag, conditional-request handling, or 304/i);
        assert.match(operation.description, /deployment-wide global limiter/i);
        assert.match(operation.responses['429'].description, /optional deployment-wide global limiter/i);
        assert.deepEqual(operation.responses['429'].headers, rateLimitHeaders);
        assert.deepEqual(operation.responses['429'].content['application/json'].example, {
            success: false,
            message: ['Too many requests.'],
            error_code: 429,
        });
        assert.doesNotMatch(JSON.stringify(operation), /mapilio\.com|real customer|production data/i);
    }

    assert.match(listOperation.description, /unauthenticated v1 alias of `GET \/api\/get-blogs`/);
    assert.match(listOperation.description, /`page`, `locale`, `category-prefix`, and `category`/);
    assert.match(listOperation.description, /limit and offset of 100/);
    assert.match(listOperation.description, /`per_page` 15/);
    assert.match(listOperation.description, /up to 12 pages.*enumerate every numeric page.*beyond 12 pages.*bounded numeric window with `\.\.\.`.*Previous and Next/s);
    assert.match(listOperation.description, /empty or out-of-range page.*\{data: null\}.*no pagination/s);
    assert.match(listOperation.description, /`posts\.id` descending/);
    assert.match(detailOperation.description, /unauthenticated v1 alias of `GET \/api\/get-blog-detail\/{slug}`/);
    assert.match(detailOperation.description, /one row.*detail pagination/s);
    assert.match(detailOperation.description, /unknown or soft-deleted slug.*HTTP 200.*\{data: null\}/s);
    for (const operation of [listOperation, detailOperation]) {
        assert.match(operation.description, /current legacy-compatible public behavior.*`author_detail`.*nullable `email` field.*does not change runtime or policy/);
    }
    assert.match(v2ListOperation.description, /thin response projection/);
    assert.match(v2ListOperation.description, /exactly `username` and `user_profile_photo`/);
    assert.match(v2ListOperation.description, /`per_page` 100.*100-row units.*101 matching posts produce two pages/s);
    assert.match(v2ListOperation.description, /preserve parsed query filters/);
    assert.match(v2DetailOperation.description, /exactly `username` and `user_profile_photo`/);
    assert.match(v2DetailOperation.description, /fixed single-row shape.*`per_page` 15.*v2-only path and links/s);
});

test('locks exact blog rows, nullable scalars, and pagination schemas', () => {
    assert.deepEqual(schemas.BlogAuthorDetail.required, [
        'id', 'username', 'email', 'user_profile_photo', 'user_bio', 'created_at', 'updated_at',
    ]);
    assert.deepEqual(schemas.BlogAuthorDetail.properties.email, { type: ['string', 'null'] });
    assert.deepEqual(Object.keys(schemas.BlogListRow.properties), listFields);
    assert.deepEqual(schemas.BlogListRow.required, listFields);
    assert.deepEqual(schemas.BlogDetailRow.required, detailFields);
    assert.deepEqual(Object.keys(schemas.BlogDetailRow.properties), detailFields);
    for (const [rowSchema, nullableId] of [[schemas.BlogListRow, false], [schemas.BlogDetailRow, true]]) {
        assert.deepEqual(rowSchema.properties.id, nullableId
            ? { type: ['integer', 'null'], format: 'int64' }
            : { type: 'integer', format: 'int64' });
        assert.deepEqual(rowSchema.properties.sort_order, { type: ['integer', 'null'] });
        assert.deepEqual(rowSchema.properties.deleted_at, { type: 'null' });
        for (const field of ['created_by_id', 'updated_by_id', 'type_id', 'author_id', 'entry_id', 'category_id']) {
            assert.deepEqual(rowSchema.properties[field], { type: ['integer', 'null'] });
        }
        for (const field of ['created_at', 'updated_at']) {
            assert.deepEqual(rowSchema.properties[field], {
                type: ['string', 'null'],
                format: 'date-time',
                pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
            });
        }
        for (const field of ['str_id', 'entry_type', 'tags', 'blog_cover_photo', 'category_name', 'other_authors']) {
            assert.deepEqual(rowSchema.properties[field], { type: ['string', 'null'] });
        }
        for (const field of ['publish_at']) {
            assert.deepEqual(rowSchema.properties[field], { type: ['string', 'null'] });
        }
        for (const field of ['featured', 'enabled']) assert.deepEqual(rowSchema.properties[field], { type: 'boolean' });
        assert.deepEqual(rowSchema.properties.author_detail, { type: 'array', maxItems: 1, items: { $ref: '#/components/schemas/BlogAuthorDetail' } });
        for (const field of ['title', 'summary', 'slug', 'meta_title', 'meta_description']) {
            assert.deepEqual(rowSchema.properties[field], { type: ['string', 'null'] });
        }
    }
    assert.deepEqual(schemas.BlogDetailRow.properties.locale, { type: ['string', 'null'] });
    assert.deepEqual(schemas.BlogDetailRow.properties.content, { type: ['string', 'null'] });

    for (const [schemaName, path] of [['BlogListPagination', '/api/get-blogs'], ['BlogDetailPagination', null]]) {
        const pagination = schemas[schemaName];
        assert.deepEqual(pagination.required, paginationFields);
        assert.deepEqual(Object.keys(pagination.properties), paginationFields);
        assert.deepEqual(pagination.properties.links.items, { $ref: '#/components/schemas/BlogPaginationLink' });
        assert.deepEqual(pagination.properties.per_page, { type: 'integer', const: 15 });
        if (path) assert.deepEqual(pagination.properties.path, { type: 'string', const: path });
    }
    assert.deepEqual(schemas.BlogListResponse.required, ['data']);
    assert.deepEqual(schemas.BlogListResponse.oneOf[0].required, ['data', 'pagination']);
    assert.equal(schemas.BlogListResponse.oneOf[1].maxProperties, 1);
    assert.deepEqual(schemas.BlogDetailResponse.required, ['data']);
    assert.deepEqual(schemas.BlogDetailResponse.oneOf[0].required, ['data', 'pagination']);
    assert.equal(schemas.BlogDetailResponse.oneOf[0].properties.data.maxItems, 1);
    assert.equal(schemas.BlogDetailResponse.oneOf[1].maxProperties, 1);
    assert.equal(schemas.BlogDetailResponse.properties.data.oneOf[0].maxItems, 1);
    for (const schemaName of ['BlogListResponse', 'BlogDetailResponse']) {
        assert.deepEqual(Object.keys(schemas[schemaName].properties), ['data', 'pagination']);
        assert.equal(schemas[schemaName].properties.data.oneOf[0].minItems, 1);
    }
    assert.deepEqual(schemas.BlogDetailPagination.properties.current_page, { type: 'integer', const: 1 });
    assert.deepEqual(schemas.BlogDetailPagination.properties.last_page, { type: 'integer', const: 1 });
    assert.deepEqual(schemas.BlogDetailPagination.properties.total, { type: 'integer', const: 0 });
    assert.deepEqual(schemas.BlogDetailPagination.properties.next_page_url, { type: 'null' });
    assert.deepEqual(schemas.BlogDetailPagination.properties.prev_page_url, { type: 'null' });

    assert.deepEqual(schemas.V2BlogAuthorDetail.required, ['username', 'user_profile_photo']);
    assert.deepEqual(Object.keys(schemas.V2BlogAuthorDetail.properties), ['username', 'user_profile_photo']);
    assert.equal(schemas.V2BlogAuthorDetail.additionalProperties, false);
    for (const [rowSchema, fields] of [[schemas.V2BlogListRow, listFields], [schemas.V2BlogDetailRow, detailFields]]) {
        assert.deepEqual(Object.keys(rowSchema.properties), fields);
        assert.deepEqual(rowSchema.required, fields);
        assert.deepEqual(rowSchema.properties.author_detail, {
            type: 'array', maxItems: 1, items: { $ref: '#/components/schemas/V2BlogAuthorDetail' },
        });
    }
    assert.deepEqual(schemas.V2BlogListPagination.properties.path, { type: 'string', const: '/api/v2/content/blogs' });
    assert.deepEqual(schemas.V2BlogListPagination.properties.per_page, { type: 'integer', const: 100 });
    assert.deepEqual(schemas.V2BlogDetailPagination.properties.per_page, { type: 'integer', const: 15 });
    assert.deepEqual(schemas.V2BlogListResponse.oneOf[0].properties.pagination, { $ref: '#/components/schemas/V2BlogListPagination' });
    assert.deepEqual(schemas.V2BlogDetailResponse.oneOf[0].properties.pagination, { $ref: '#/components/schemas/V2BlogDetailPagination' });
});

test('keeps synthetic populated and empty examples exact', () => {
    const listResponse = listOperation.responses['200'].content['application/json'];
    const detailResponse = detailOperation.responses['200'].content['application/json'];
    const populatedList = listResponse.examples.populated.value;
    const populatedDetail = detailResponse.examples.populated.value;
    assert.deepEqual(Object.keys(listResponse.examples), ['populated', 'empty']);
    assert.deepEqual(Object.keys(detailResponse.examples), ['populated', 'unknownSlug']);
    assert.deepEqual(populatedList.data.map((row) => Object.keys(row)), [listFields, listFields]);
    assert.deepEqual(populatedDetail.data.map((row) => Object.keys(row)), [detailFields]);
    assert.deepEqual(populatedList.data.map(({ id, featured, enabled }) => ({ id, featured, enabled })), [
        { id: 102, featured: false, enabled: true },
        { id: 101, featured: true, enabled: true },
    ]);
    assert.equal(populatedList.data[1].other_authors, '[{"username":"synthetic-coauthor","user_profile_photo":"https://cdn.example/coauthor.jpg"}]');
    assert.deepEqual(populatedList.data[0].author_detail, []);
    assert.equal(populatedList.data[0].created_at, null);
    assert.equal(populatedList.data[0].publish_at, null);
    assert.equal(populatedList.pagination.per_page, 15);
    assert.equal(populatedList.pagination.path, '/api/get-blogs');
    assert.deepEqual(listResponse.examples.empty.value, { data: null });
    assert.equal(populatedDetail.data[0].id, 1001);
    assert.equal(populatedDetail.data[0].locale, 'en');
    assert.equal(populatedDetail.data[0].content, '<p>Synthetic blog content.</p>');
    assert.equal(populatedDetail.pagination.total, 0);
    assert.equal(populatedDetail.pagination.path, '/api/get-blog-detail/synthetic-blog-101-en');
    assert.deepEqual(detailResponse.examples.unknownSlug.value, { data: null });

    const v2ListResponse = v2ListOperation.responses['200'].content['application/json'];
    const v2DetailResponse = v2DetailOperation.responses['200'].content['application/json'];
    assert.deepEqual(Object.keys(v2ListResponse.examples), ['populated', 'empty']);
    assert.deepEqual(Object.keys(v2DetailResponse.examples), ['populated', 'unknownSlug']);
    assert.deepEqual(Object.keys(v2ListResponse.examples.populated.value.data[0]), listFields);
    assert.deepEqual(Object.keys(v2DetailResponse.examples.populated.value.data[0]), detailFields);
    assert.deepEqual(v2ListResponse.examples.populated.value.data[0].author_detail, [{
        username: 'synthetic-author', user_profile_photo: 'https://cdn.example/author.jpg',
    }]);
    assert.deepEqual(v2DetailResponse.examples.populated.value.data[0].author_detail, [{
        username: 'synthetic-author', user_profile_photo: 'https://cdn.example/author.jpg',
    }]);
    assert.equal(v2ListResponse.examples.populated.value.pagination.per_page, 100);
    assert.equal(v2ListResponse.examples.populated.value.pagination.path, '/api/v2/content/blogs');
    assert.equal(v2DetailResponse.examples.populated.value.pagination.path, '/api/v2/content/blogs/synthetic-blog-101-en');
    assert.deepEqual(v2ListResponse.examples.empty.value, { data: null });
    assert.deepEqual(v2DetailResponse.examples.unknownSlug.value, { data: null });
});

test('guards route, controller, query, PHP coverage, limiter, package registration, and generated docs', async () => {
    const [routes, controller, v2Controller, query, bootstrap, limiter, compatibility, packageSource, generatedHtml] = await Promise.all([
        readText('routes/api.php'),
        readText('app/Http/Controllers/Legacy/PublicContent/BlogContentController.php'),
        readText('app/Http/Controllers/Api/V2/Content/BlogContentController.php'),
        readText('app/Domain/PublicContent/Queries/BlogContentQuery.php'),
        readText('bootstrap/app.php'),
        readText('app/Http/Middleware/ThrottleApiRequests.php'),
        readText('tests/Feature/Legacy/PublicContentCompatibilityTest.php'),
        readText('package.json'),
        readText('public/docs/api/index.html'),
    ]);
    const legacyList = routes.match(/Route::get\('get-blogs', \[BlogContentController::class, 'blogs'\]\)\s*->name\('api\.legacy\.blogs'\);/);
    const legacyDetail = routes.match(/Route::get\('get-blog-detail\/{slug}', \[BlogContentController::class, 'detail'\]\)\s*->name\('api\.legacy\.blog-detail'\);/);
    const versionedList = routes.match(/Route::get\('content\/blogs', \[BlogContentController::class, 'blogs'\]\)\s*->name\('content\.blogs'\);/);
    const versionedDetail = routes.match(/Route::get\('content\/blogs\/{slug}', \[BlogContentController::class, 'detail'\]\)\s*->name\('content\.blogs\.detail'\);/);
    const v2List = routes.match(/Route::get\('content\/blogs', \[V2BlogContentController::class, 'blogs'\]\)\s*->name\('content\.blogs'\);/);
    const v2Detail = routes.match(/Route::get\('content\/blogs\/{slug}', \[V2BlogContentController::class, 'detail'\]\)\s*->name\('content\.blogs\.detail'\);/);
    for (const route of [legacyList, legacyDetail, versionedList, versionedDetail, v2List, v2Detail]) {
        assert.ok(route);
    }
    assert.match(controller, /return response\(\)->json\(\$query->blogs\(\$request\)\);/);
    assert.match(controller, /return response\(\)->json\(\$query->detail\(\$request, \$slug\)\);/);
    assert.match(query, /default_posts_posts/);
    assert.match(query, /default_posts_posts_translations/);
    assert.match(query, /default_posts_default_posts_translations/);
    assert.match(query, /default_posts_default_posts_other_author_field/);
    assert.match(query, /private const DATA_PAGE_SIZE = 100/);
    assert.match(query, /private const LEGACY_PAGINATION_SIZE = 15/);
    assert.match(query, /int \$paginationSize = self::LEGACY_PAGINATION_SIZE/);
    assert.match(query, /max\(1, \(int\) \$request->query\('page', 1\)\)/);
    assert.match(query, /is_string\(\$value\) && \$value !== '' \? \$value : \$default/);
    assert.match(query, /'category-prefix'/);
    assert.match(query, /'category'/);
    assert.match(query, /->whereNull\('posts\.deleted_at'\)/);
    assert.match(query, /->where\('posts\.enabled', true\)/);
    assert.match(query, /->orderByDesc\('posts\.id'\)/);
    assert.match(query, /->limit\(self::DATA_PAGE_SIZE\)/);
    assert.match(query, /return \['data' => null\]/);
    assert.match(query, /private function detailPagination/);
    assert.match(query, /if \(\$lastPage <= 12\)/);
    assert.match(query, /\['\.\.\.'\]/);
    assert.match(query, /http_build_query\(\$query\)/);
    assert.match(v2Controller, /PAGINATION_SIZE = 100/);
    assert.match(v2Controller, /author_detail/);
    assert.match(v2Controller, /'username' => \$author\['username'\] \?\? null/);
    assert.match(v2Controller, /'user_profile_photo' => \$author\['user_profile_photo'\] \?\? null/);
    assert.match(bootstrap, /ThrottleApiRequests::class/);
    assert.match(limiter, /config\('mapilio\.rate_limiting\.enabled', false\)/);
    assert.match(limiter, /if \(\$enforce\) \{\s*return \$this->tooManyRequests/s);
    assert.match(limiter, /'error_code' => Response::HTTP_TOO_MANY_REQUESTS/);
    for (const testName of [
        'test_legacy_get_blogs_preserves_blog_payload_shape',
        'test_public_content_malformed_timestamps_return_null',
        'test_blog_other_authors_substitute_invalid_utf8_on_list_and_detail',
        'test_legacy_get_blogs_empty_results_return_data_null',
        'test_versioned_blogs_alias_returns_same_contract',
        'test_legacy_blog_detail_uses_translation_overrides_and_detail_pagination',
        'test_legacy_blog_detail_missing_slug_returns_data_null',
        'test_versioned_blog_detail_alias_returns_same_contract',
        'test_v2_blog_list_projects_author_detail_to_public_fields_and_v2_pagination',
        'test_v2_blog_detail_projects_author_detail_and_keeps_v2_pagination',
        'test_v2_blog_authors_are_empty_for_null_and_missing_users',
        'test_v2_blog_missing_locale_keeps_list_rows_but_returns_null_detail',
        'test_v2_blog_list_pagination_keeps_query_and_all_links_on_v2_route',
        'test_versioned_blog_routes_only_use_shared_api_group_middleware',
    ]) assert.match(compatibility, new RegExp(`function ${testName}\\(`));
    const packageJson = JSON.parse(packageSource);
    assert.match(packageJson.scripts['test:blog-contract'], /node --test scripts\/docs\/blog-contract\.test\.mjs/);
    assert.match(packageJson.scripts['validate:api-examples'], /scripts\/docs\/blog-contract\.test\.mjs/);
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
