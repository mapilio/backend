<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicContentCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_posts_categories', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('slug')->nullable();
        });

        Schema::create('default_posts_categories_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->string('locale');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
        });

        Schema::create('default_posts_posts', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('str_id')->nullable();
            $table->integer('type_id')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->integer('author_id')->nullable();
            $table->integer('entry_id')->nullable();
            $table->string('entry_type')->nullable();
            $table->integer('category_id')->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('tags')->nullable();
            $table->string('blog_cover_photo')->nullable();
        });

        Schema::create('default_posts_posts_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->string('locale');
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('slug')->nullable();
        });

        Schema::create('default_posts_default_posts', function ($table): void {
            $table->id();
        });

        Schema::create('default_posts_default_posts_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->string('locale');
            $table->text('content')->nullable();
        });

        Schema::create('default_posts_default_posts_other_author_field', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->integer('related_id');
            $table->integer('sort_order')->nullable();
        });

        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('user_profile_photo')->nullable();
            $table->text('user_bio')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::getConnection()->table('default_posts_categories')->insert([
            [
                'id' => 10,
                'sort_order' => 1,
                'created_at' => '2026-03-01 01:02:03',
                'created_by_id' => 5,
                'updated_at' => '2026-03-02 01:02:03',
                'updated_by_id' => 6,
                'deleted_at' => null,
                'slug' => 'blog-news',
            ],
            [
                'id' => 11,
                'sort_order' => 2,
                'created_at' => '2026-03-03 01:02:03',
                'created_by_id' => 5,
                'updated_at' => '2026-03-04 01:02:03',
                'updated_by_id' => 6,
                'deleted_at' => null,
                'slug' => 'catalog-news',
            ],
            [
                'id' => 12,
                'sort_order' => 3,
                'created_at' => '2026-03-05 01:02:03',
                'created_by_id' => 5,
                'updated_at' => '2026-03-06 01:02:03',
                'updated_by_id' => 6,
                'deleted_at' => '2026-03-07 01:02:03',
                'slug' => 'blog-deleted',
            ],
        ]);

        Schema::getConnection()->table('default_posts_categories_translations')->insert([
            [
                'entry_id' => 10,
                'locale' => 'en',
                'name' => 'Blog News',
                'description' => 'News description',
                'meta_title' => 'News meta title',
                'meta_description' => 'News meta description',
            ],
            [
                'entry_id' => 11,
                'locale' => 'en',
                'name' => 'Catalog News',
                'description' => 'Catalog description',
                'meta_title' => 'Catalog meta title',
                'meta_description' => 'Catalog meta description',
            ],
        ]);

        Schema::getConnection()->table('default_users_users')->insert([
            [
                'id' => 501,
                'username' => 'author',
                'email' => 'author@example.test',
                'user_profile_photo' => 'https://cdn.example/author.jpg',
                'user_bio' => 'Main author',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-02 00:00:00',
            ],
            [
                'id' => 502,
                'username' => 'coauthor',
                'email' => 'coauthor@example.test',
                'user_profile_photo' => 'https://cdn.example/coauthor.jpg',
                'user_bio' => null,
                'created_at' => '2026-01-03 00:00:00',
                'updated_at' => '2026-01-04 00:00:00',
            ],
        ]);

        Schema::getConnection()->table('default_posts_default_posts')->insert([
            ['id' => 701],
            ['id' => 702],
        ]);

        Schema::getConnection()->table('default_posts_default_posts_translations')->insert([
            [
                'entry_id' => 701,
                'locale' => 'en',
                'content' => '<p>Full blog content</p>',
            ],
        ]);

        Schema::getConnection()->table('default_posts_posts')->insert([
            [
                'id' => 101,
                'sort_order' => 7,
                'created_at' => '2026-04-01 05:06:07',
                'created_by_id' => 501,
                'updated_at' => '2026-04-02 05:06:07',
                'updated_by_id' => null,
                'deleted_at' => null,
                'str_id' => 'abc123',
                'type_id' => 1,
                'publish_at' => '2026-04-03 05:06:07',
                'author_id' => 501,
                'entry_id' => 701,
                'entry_type' => 'posts_default_posts',
                'category_id' => 10,
                'featured' => true,
                'enabled' => true,
                'tags' => 'street-level',
                'blog_cover_photo' => 'https://cdn.example/cover.jpg',
            ],
            [
                'id' => 102,
                'sort_order' => 8,
                'created_at' => '2026-04-04 05:06:07',
                'created_by_id' => 501,
                'updated_at' => '2026-04-05 05:06:07',
                'updated_by_id' => null,
                'deleted_at' => null,
                'str_id' => 'catalog',
                'type_id' => 1,
                'publish_at' => '2026-04-06 05:06:07',
                'author_id' => 501,
                'entry_id' => 702,
                'entry_type' => 'posts_default_posts',
                'category_id' => 11,
                'featured' => false,
                'enabled' => true,
                'tags' => null,
                'blog_cover_photo' => null,
            ],
            [
                'id' => 103,
                'sort_order' => 9,
                'created_at' => '2026-04-07 05:06:07',
                'created_by_id' => 501,
                'updated_at' => '2026-04-08 05:06:07',
                'updated_by_id' => null,
                'deleted_at' => null,
                'str_id' => 'disabled',
                'type_id' => 1,
                'publish_at' => '2026-04-09 05:06:07',
                'author_id' => 501,
                'entry_id' => 703,
                'entry_type' => 'posts_default_posts',
                'category_id' => 10,
                'featured' => false,
                'enabled' => false,
                'tags' => null,
                'blog_cover_photo' => null,
            ],
        ]);

        Schema::getConnection()->table('default_posts_posts_translations')->insert([
            [
                'id' => 1001,
                'entry_id' => 101,
                'created_at' => '2026-04-01 06:00:00',
                'created_by_id' => null,
                'updated_at' => '2026-04-02 06:00:00',
                'updated_by_id' => null,
                'locale' => 'en',
                'title' => 'Modern Blog',
                'summary' => 'Short summary',
                'meta_title' => 'Modern meta title',
                'meta_description' => 'Modern meta description',
                'slug' => 'modern-blog-101-en',
            ],
            [
                'id' => 1002,
                'entry_id' => 102,
                'created_at' => '2026-04-04 06:00:00',
                'created_by_id' => null,
                'updated_at' => '2026-04-05 06:00:00',
                'updated_by_id' => null,
                'locale' => 'en',
                'title' => 'Catalog Blog',
                'summary' => 'Catalog summary',
                'meta_title' => 'Catalog post meta title',
                'meta_description' => 'Catalog post meta description',
                'slug' => 'catalog-blog-102-en',
            ],
        ]);

        Schema::getConnection()->table('default_posts_default_posts_other_author_field')->insert([
            [
                'entry_id' => 701,
                'related_id' => 502,
                'sort_order' => 1,
            ],
        ]);
    }

    public function test_legacy_get_categories_preserves_wrapper_and_pagination_shape(): void
    {
        $this->getJson('/api/get-categories')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 10,
                        'sort_order' => 1,
                        'created_at' => '2026-03-01T01:02:03.000000Z',
                        'created_by_id' => 5,
                        'updated_at' => '2026-03-02T01:02:03.000000Z',
                        'updated_by_id' => 6,
                        'deleted_at' => null,
                        'slug' => 'blog-news',
                        'name' => 'Blog News',
                        'description' => 'News description',
                        'meta_title' => 'News meta title',
                        'meta_description' => 'News meta description',
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/get-categories?page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/get-categories?page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/get-categories?page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/get-categories',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 1,
                    'total' => 1,
                ],
            ]);
    }

    public function test_legacy_get_categories_empty_results_return_data_null(): void
    {
        $this->getJson('/api/get-categories?prefix=none-')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);

        $this->getJson('/api/get-categories?page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_versioned_categories_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-categories')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/content/categories')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_legacy_get_blogs_preserves_blog_payload_shape(): void
    {
        $this->getJson('/api/get-blogs')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 101,
                        'sort_order' => 7,
                        'created_at' => '2026-04-01T05:06:07.000000Z',
                        'created_by_id' => 501,
                        'updated_at' => '2026-04-02T05:06:07.000000Z',
                        'updated_by_id' => null,
                        'deleted_at' => null,
                        'str_id' => 'abc123',
                        'type_id' => 1,
                        'publish_at' => '2026-04-03 05:06:07',
                        'author_id' => 501,
                        'entry_id' => 701,
                        'entry_type' => 'posts_default_posts',
                        'category_id' => 10,
                        'featured' => true,
                        'enabled' => true,
                        'tags' => 'street-level',
                        'blog_cover_photo' => 'https://cdn.example/cover.jpg',
                        'category_name' => 'Blog News',
                        'other_authors' => '[{"username":"coauthor","user_profile_photo":"https://cdn.example/coauthor.jpg"}]',
                        'author_detail' => [
                            [
                                'id' => 501,
                                'username' => 'author',
                                'email' => 'author@example.test',
                                'user_profile_photo' => 'https://cdn.example/author.jpg',
                                'user_bio' => 'Main author',
                                'created_at' => '2026-01-01T00:00:00.000000Z',
                                'updated_at' => '2026-01-02T00:00:00.000000Z',
                            ],
                        ],
                        'title' => 'Modern Blog',
                        'summary' => 'Short summary',
                        'slug' => 'modern-blog-101-en',
                        'meta_title' => 'Modern meta title',
                        'meta_description' => 'Modern meta description',
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/get-blogs?page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/get-blogs?page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/get-blogs?page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/get-blogs',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 1,
                    'total' => 1,
                ],
            ]);
    }

    public function test_legacy_get_blogs_empty_results_return_data_null(): void
    {
        $this->getJson('/api/get-blogs?category=999999')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);

        $this->getJson('/api/get-blogs?category-prefix=none-')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);

        $this->getJson('/api/get-blogs?page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_versioned_blogs_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-blogs')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/content/blogs')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_legacy_blog_detail_uses_translation_overrides_and_detail_pagination(): void
    {
        $this->getJson('/api/get-blog-detail/modern-blog-101-en')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 1001,
                        'sort_order' => 7,
                        'created_at' => '2026-04-01T06:00:00.000000Z',
                        'created_by_id' => null,
                        'updated_at' => '2026-04-02T06:00:00.000000Z',
                        'updated_by_id' => null,
                        'deleted_at' => null,
                        'str_id' => 'abc123',
                        'type_id' => 1,
                        'publish_at' => '2026-04-03 05:06:07',
                        'author_id' => 501,
                        'entry_id' => 101,
                        'entry_type' => 'posts_default_posts',
                        'category_id' => 10,
                        'featured' => true,
                        'enabled' => true,
                        'tags' => 'street-level',
                        'blog_cover_photo' => 'https://cdn.example/cover.jpg',
                        'locale' => 'en',
                        'title' => 'Modern Blog',
                        'summary' => 'Short summary',
                        'meta_title' => 'Modern meta title',
                        'meta_description' => 'Modern meta description',
                        'slug' => 'modern-blog-101-en',
                        'category_name' => 'Blog News',
                        'content' => '<p>Full blog content</p>',
                        'other_authors' => '[{"username":"coauthor","user_profile_photo":"https://cdn.example/coauthor.jpg"}]',
                        'author_detail' => [
                            [
                                'id' => 501,
                                'username' => 'author',
                                'email' => 'author@example.test',
                                'user_profile_photo' => 'https://cdn.example/author.jpg',
                                'user_bio' => 'Main author',
                                'created_at' => '2026-01-01T00:00:00.000000Z',
                                'updated_at' => '2026-01-02T00:00:00.000000Z',
                            ],
                        ],
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/get-blog-detail/modern-blog-101-en?page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/get-blog-detail/modern-blog-101-en?page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/get-blog-detail/modern-blog-101-en?page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/get-blog-detail/modern-blog-101-en',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 1,
                    'total' => 0,
                ],
            ]);
    }

    public function test_legacy_blog_detail_missing_slug_returns_data_null(): void
    {
        $this->getJson('/api/get-blog-detail/missing-slug')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_versioned_blog_detail_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-blog-detail/modern-blog-101-en')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/content/blogs/modern-blog-101-en')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }
}
