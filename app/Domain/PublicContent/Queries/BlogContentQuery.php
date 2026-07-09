<?php

namespace App\Domain\PublicContent\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BlogContentQuery
{
    private const DATA_PAGE_SIZE = 100;

    private const LEGACY_PAGINATION_SIZE = 15;

    public function categories(Request $request): array
    {
        $connection = $this->connection();
        $page = $this->page($request);
        $locale = $this->locale($request);
        $prefix = $this->filledString($request->query('prefix'), 'blog-');
        $path = '/api/get-categories';

        $baseQuery = $connection->table('default_posts_categories as categories')
            ->leftJoin('default_posts_categories_translations as translations', function ($join) use ($locale): void {
                $join->on('translations.entry_id', '=', 'categories.id')
                    ->where('translations.locale', '=', $locale);
            })
            ->whereNull('categories.deleted_at')
            ->where('categories.slug', 'LIKE', "%{$prefix}%");

        $total = (clone $baseQuery)->count();

        $rows = $baseQuery
            ->select([
                'categories.id',
                'categories.sort_order',
                'categories.created_at',
                'categories.created_by_id',
                'categories.updated_at',
                'categories.updated_by_id',
                'categories.deleted_at',
                'categories.slug',
                'translations.name',
                'translations.description',
                'translations.meta_title',
                'translations.meta_description',
            ])
            ->orderBy('categories.id')
            ->limit(self::DATA_PAGE_SIZE)
            ->offset($this->offset($page))
            ->get()
            ->map(fn (object $row): array => $this->mapCategory($row))
            ->all();

        if ($rows === []) {
            return ['data' => null];
        }

        return [
            'data' => $rows,
            'pagination' => $this->pagination($request, $path, $page, $total, count($rows)),
        ];
    }

    public function blogs(Request $request): array
    {
        $connection = $this->connection();
        $page = $this->page($request);
        $locale = $this->locale($request);
        $categoryPrefix = $this->filledString($request->query('category-prefix'), 'blog-');
        $path = '/api/get-blogs';

        $baseQuery = $connection->table('default_posts_posts as posts')
            ->leftJoin('default_posts_categories_translations as category_translations', function ($join) use ($locale): void {
                $join->on('posts.category_id', '=', 'category_translations.entry_id')
                    ->where('category_translations.locale', '=', $locale);
            })
            ->leftJoin('default_posts_categories as categories', 'posts.category_id', '=', 'categories.id')
            ->leftJoin('default_posts_posts_translations as post_translations', function ($join) use ($locale): void {
                $join->on('posts.id', '=', 'post_translations.entry_id')
                    ->where('post_translations.locale', '=', $locale);
            })
            ->whereNull('posts.deleted_at')
            ->where('posts.enabled', true)
            ->where('categories.slug', 'LIKE', "%{$categoryPrefix}%");

        if ($this->hasFilledQueryValue($request, 'category')) {
            $baseQuery->where('posts.category_id', $request->query('category'));
        }

        $total = (clone $baseQuery)->count();

        $rows = $baseQuery
            ->select($this->blogListColumns())
            ->orderByDesc('posts.id')
            ->limit(self::DATA_PAGE_SIZE)
            ->offset($this->offset($page))
            ->get();

        if ($rows->isEmpty()) {
            return ['data' => null];
        }

        $authors = $this->authorDetails($connection, $rows->pluck('author_id')->filter()->unique()->values());
        $otherAuthors = $this->otherAuthors($connection, $rows->pluck('entry_id')->filter()->unique()->values());

        return [
            'data' => $rows
                ->map(fn (object $row): array => $this->mapBlogListRow($row, $authors, $otherAuthors))
                ->all(),
            'pagination' => $this->pagination($request, $path, $page, $total, $rows->count()),
        ];
    }

    public function detail(Request $request, string $slug): array
    {
        $connection = $this->connection();
        $locale = $this->locale($request);
        $path = '/api/get-blog-detail/'.$slug;

        $row = $connection->table('default_posts_posts as posts')
            ->leftJoin('default_posts_posts_translations as post_translations', function ($join) use ($locale): void {
                $join->on('posts.id', '=', 'post_translations.entry_id')
                    ->where('post_translations.locale', '=', $locale);
            })
            ->leftJoin('default_posts_categories_translations as category_translations', function ($join) use ($locale): void {
                $join->on('posts.category_id', '=', 'category_translations.entry_id')
                    ->where('category_translations.locale', '=', $locale);
            })
            ->leftJoin('default_posts_default_posts as default_posts', 'posts.entry_id', '=', 'default_posts.id')
            ->leftJoin('default_posts_default_posts_translations as default_post_translations', function ($join) use ($locale): void {
                $join->on('default_posts.id', '=', 'default_post_translations.entry_id')
                    ->where('default_post_translations.locale', '=', $locale);
            })
            ->whereNull('posts.deleted_at')
            ->where('post_translations.slug', $slug)
            ->select($this->blogDetailColumns())
            ->first();

        if ($row === null) {
            return ['data' => null];
        }

        $authors = $this->authorDetails($connection, collect([$row->author_id])->filter()->values());
        $otherAuthors = $this->otherAuthors($connection, collect([$row->content_entry_id])->filter()->values());

        return [
            'data' => [
                $this->mapBlogDetailRow($row, $authors, $otherAuthors),
            ],
            'pagination' => $this->detailPagination($request, $path),
        ];
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(config('mapilio.legacy_database_connection'));
    }

    /**
     * @return list<string>
     */
    private function blogListColumns(): array
    {
        return [
            'posts.id',
            'posts.sort_order',
            'posts.created_at',
            'posts.created_by_id',
            'posts.updated_at',
            'posts.updated_by_id',
            'posts.deleted_at',
            'posts.str_id',
            'posts.type_id',
            'posts.publish_at',
            'posts.author_id',
            'posts.entry_id',
            'posts.entry_type',
            'posts.category_id',
            'posts.featured',
            'posts.enabled',
            'posts.tags',
            'posts.blog_cover_photo',
            'category_translations.name as category_name',
            'post_translations.title',
            'post_translations.summary',
            'post_translations.slug',
            'post_translations.meta_title',
            'post_translations.meta_description',
        ];
    }

    /**
     * @return list<string>
     */
    private function blogDetailColumns(): array
    {
        return [
            'posts.id as post_id',
            'posts.sort_order',
            'posts.deleted_at',
            'posts.str_id',
            'posts.type_id',
            'posts.publish_at',
            'posts.author_id',
            'posts.entry_id as content_entry_id',
            'posts.entry_type',
            'posts.category_id',
            'posts.featured',
            'posts.enabled',
            'posts.tags',
            'posts.blog_cover_photo',
            'post_translations.id',
            'post_translations.entry_id',
            'post_translations.created_at',
            'post_translations.created_by_id',
            'post_translations.updated_at',
            'post_translations.updated_by_id',
            'post_translations.locale',
            'post_translations.title',
            'post_translations.summary',
            'post_translations.meta_title',
            'post_translations.meta_description',
            'post_translations.slug',
            'category_translations.name as category_name',
            'default_post_translations.content',
        ];
    }

    /**
     * @param  Collection<int, mixed>  $authorIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function authorDetails(ConnectionInterface $connection, Collection $authorIds): array
    {
        if ($authorIds->isEmpty()) {
            return [];
        }

        return $connection->table('default_users_users')
            ->select([
                'id',
                'username',
                'email',
                'user_profile_photo',
                'user_bio',
                'created_at',
                'updated_at',
            ])
            ->whereIn('id', $authorIds->all())
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->id => [[
                    'id' => (int) $row->id,
                    'username' => $row->username,
                    'email' => $row->email,
                    'user_profile_photo' => $row->user_profile_photo,
                    'user_bio' => $row->user_bio,
                    'created_at' => $this->timestamp($row->created_at),
                    'updated_at' => $this->timestamp($row->updated_at),
                ]],
            ])
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $entryIds
     * @return array<int, string>
     */
    private function otherAuthors(ConnectionInterface $connection, Collection $entryIds): array
    {
        if ($entryIds->isEmpty()) {
            return [];
        }

        $rows = $connection->table('default_posts_default_posts_other_author_field as other_author_field')
            ->join('default_users_users as users', 'users.id', '=', 'other_author_field.related_id')
            ->select([
                'other_author_field.entry_id',
                'other_author_field.sort_order',
                'other_author_field.id as pivot_id',
                'users.username',
                'users.user_profile_photo',
            ])
            ->whereIn('other_author_field.entry_id', $entryIds->all())
            ->orderBy('other_author_field.entry_id')
            ->orderBy('other_author_field.sort_order')
            ->orderBy('other_author_field.id')
            ->get();

        return $rows
            ->groupBy(fn (object $row): int => (int) $row->entry_id)
            ->map(fn (Collection $authors): string => json_encode(
                $authors
                    ->map(fn (object $author): array => [
                        'username' => $author->username,
                        'user_profile_photo' => $author->user_profile_photo,
                    ])
                    ->values()
                    ->all(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->all();
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $authors
     * @param  array<int, string>  $otherAuthors
     */
    private function mapBlogListRow(object $row, array $authors, array $otherAuthors): array
    {
        return [
            'id' => (int) $row->id,
            'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
            'created_at' => $this->timestamp($row->created_at),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'updated_at' => $this->timestamp($row->updated_at),
            'updated_by_id' => $row->updated_by_id === null ? null : (int) $row->updated_by_id,
            'deleted_at' => $this->timestamp($row->deleted_at),
            'str_id' => $row->str_id,
            'type_id' => $row->type_id === null ? null : (int) $row->type_id,
            'publish_at' => $this->databaseTimestamp($row->publish_at),
            'author_id' => $row->author_id === null ? null : (int) $row->author_id,
            'entry_id' => $row->entry_id === null ? null : (int) $row->entry_id,
            'entry_type' => $row->entry_type,
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'featured' => (bool) $row->featured,
            'enabled' => (bool) $row->enabled,
            'tags' => $row->tags,
            'blog_cover_photo' => $row->blog_cover_photo,
            'category_name' => $row->category_name,
            'other_authors' => $row->entry_id === null ? null : ($otherAuthors[(int) $row->entry_id] ?? null),
            'author_detail' => $row->author_id === null ? [] : ($authors[(int) $row->author_id] ?? []),
            'title' => $row->title,
            'summary' => $row->summary,
            'slug' => $row->slug,
            'meta_title' => $row->meta_title,
            'meta_description' => $row->meta_description,
        ];
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $authors
     * @param  array<int, string>  $otherAuthors
     */
    private function mapBlogDetailRow(object $row, array $authors, array $otherAuthors): array
    {
        return [
            'id' => $row->id === null ? null : (int) $row->id,
            'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
            'created_at' => $this->timestamp($row->created_at),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'updated_at' => $this->timestamp($row->updated_at),
            'updated_by_id' => $row->updated_by_id === null ? null : (int) $row->updated_by_id,
            'deleted_at' => $this->timestamp($row->deleted_at),
            'str_id' => $row->str_id,
            'type_id' => $row->type_id === null ? null : (int) $row->type_id,
            'publish_at' => $this->databaseTimestamp($row->publish_at),
            'author_id' => $row->author_id === null ? null : (int) $row->author_id,
            'entry_id' => $row->entry_id === null ? null : (int) $row->entry_id,
            'entry_type' => $row->entry_type,
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'featured' => (bool) $row->featured,
            'enabled' => (bool) $row->enabled,
            'tags' => $row->tags,
            'blog_cover_photo' => $row->blog_cover_photo,
            'locale' => $row->locale,
            'title' => $row->title,
            'summary' => $row->summary,
            'meta_title' => $row->meta_title,
            'meta_description' => $row->meta_description,
            'slug' => $row->slug,
            'category_name' => $row->category_name,
            'content' => $row->content,
            'other_authors' => $row->content_entry_id === null ? null : ($otherAuthors[(int) $row->content_entry_id] ?? null),
            'author_detail' => $row->author_id === null ? [] : ($authors[(int) $row->author_id] ?? []),
        ];
    }

    private function mapCategory(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
            'created_at' => $this->timestamp($row->created_at),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'updated_at' => $this->timestamp($row->updated_at),
            'updated_by_id' => $row->updated_by_id === null ? null : (int) $row->updated_by_id,
            'deleted_at' => $this->timestamp($row->deleted_at),
            'slug' => $row->slug,
            'name' => $row->name,
            'description' => $row->description,
            'meta_title' => $row->meta_title,
            'meta_description' => $row->meta_description,
        ];
    }

    private function locale(Request $request): string
    {
        return $this->filledString($request->query('locale'), app()->getLocale());
    }

    private function filledString(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function hasFilledQueryValue(Request $request, string $key): bool
    {
        $value = $request->query($key);

        return $value !== null && $value !== '';
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    private function offset(int $page): int
    {
        return ($page - 1) * self::DATA_PAGE_SIZE;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return date('Y-m-d\TH:i:s.000000\Z', strtotime((string) $value));
    }

    private function databaseTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime((string) $value));
    }

    private function pagination(Request $request, string $path, int $page, int $total, int $rowCount): array
    {
        $lastPage = (int) ceil($total / self::LEGACY_PAGINATION_SIZE);
        $from = (($page - 1) * self::LEGACY_PAGINATION_SIZE) + 1;

        return [
            'current_page' => $page,
            'first_page_url' => $this->pageUrl($path, $request, 1),
            'from' => $from,
            'last_page' => $lastPage,
            'last_page_url' => $this->pageUrl($path, $request, $lastPage),
            'links' => $this->links($path, $request, $page, $lastPage),
            'next_page_url' => $page < $lastPage ? $this->pageUrl($path, $request, $page + 1) : null,
            'path' => $path,
            'per_page' => self::LEGACY_PAGINATION_SIZE,
            'prev_page_url' => $page > 1 ? $this->pageUrl($path, $request, $page - 1) : null,
            'to' => $from + $rowCount - 1,
            'total' => $total,
        ];
    }

    private function detailPagination(Request $request, string $path): array
    {
        return [
            'current_page' => 1,
            'first_page_url' => $this->pageUrl($path, $request, 1),
            'from' => 1,
            'last_page' => 1,
            'last_page_url' => $this->pageUrl($path, $request, 1),
            'links' => [
                ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                ['url' => $this->pageUrl($path, $request, 1), 'label' => '1', 'active' => true],
                ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
            ],
            'next_page_url' => null,
            'path' => $path,
            'per_page' => self::LEGACY_PAGINATION_SIZE,
            'prev_page_url' => null,
            'to' => 1,
            'total' => 0,
        ];
    }

    /**
     * @return list<array{url: string|null, label: string, active: bool}>
     */
    private function links(string $path, Request $request, int $page, int $lastPage): array
    {
        $links = [
            [
                'url' => $page > 1 ? $this->pageUrl($path, $request, $page - 1) : null,
                'label' => '&laquo; Previous',
                'active' => false,
            ],
        ];

        foreach ($this->pageWindow($page, $lastPage) as $item) {
            if ($item === '...') {
                $links[] = [
                    'url' => null,
                    'label' => '...',
                    'active' => false,
                ];

                continue;
            }

            $links[] = [
                'url' => $this->pageUrl($path, $request, $item),
                'label' => (string) $item,
                'active' => $item === $page,
            ];
        }

        $links[] = [
            'url' => $page < $lastPage ? $this->pageUrl($path, $request, $page + 1) : null,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }

    /**
     * @return list<int|string>
     */
    private function pageWindow(int $page, int $lastPage): array
    {
        if ($lastPage <= 0) {
            return [];
        }

        if ($lastPage <= 12) {
            return range(1, $lastPage);
        }

        if ($page <= 10) {
            return array_merge(range(1, 10), ['...'], [$lastPage - 1, $lastPage]);
        }

        if ($page >= $lastPage - 9) {
            return array_merge([1, 2], ['...'], range($lastPage - 9, $lastPage));
        }

        return [1, 2, '...', $page - 1, $page, $page + 1, '...', $lastPage - 1, $lastPage];
    }

    private function pageUrl(string $path, Request $request, int $page): string
    {
        $query = $request->query();
        $query['page'] = $page;

        return $path.'?'.http_build_query($query);
    }
}
