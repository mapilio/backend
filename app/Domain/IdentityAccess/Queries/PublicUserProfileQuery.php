<?php

namespace App\Domain\IdentityAccess\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Profile array{
 *     id: mixed,
 *     username: mixed,
 *     user_profile_photo: mixed,
 *     user_bio: mixed,
 *     created_at: ?string,
 *     updated_at: ?string,
 *     km: string,
 *     photos: int
 * }
 * @phpstan-type PaginationLink array{url: ?string, label: string, active: bool}
 * @phpstan-type Pagination array{
 *     current_page: int,
 *     first_page_url: string,
 *     from: int,
 *     last_page: int,
 *     last_page_url: string,
 *     links: list<PaginationLink>,
 *     next_page_url: ?string,
 *     path: string,
 *     per_page: int,
 *     prev_page_url: ?string,
 *     to: int,
 *     total: int
 * }
 */
class PublicUserProfileQuery
{
    private const LEGACY_PAGINATION_SIZE = 15;

    /**
     * @return array{data: null}|array{data: non-empty-list<Profile>, pagination: Pagination}
     */
    public function byId(int $userId, Request $request): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $path = '/api/search-user';

        $user = $connection->table('default_users_users')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->first([
                'id',
                'username',
                'user_profile_photo',
                'user_bio',
                'created_at',
                'updated_at',
            ]);

        if ($user === null) {
            return [
                'data' => null,
            ];
        }

        return [
            'data' => [
                [
                    'id' => $user->id,
                    'username' => $user->username,
                    'user_profile_photo' => $user->user_profile_photo
                        ?: config('mapilio.mobile_auth.default_profile_photo_url'),
                    'user_bio' => $user->user_bio,
                    'created_at' => $this->timestamp($user->created_at),
                    'updated_at' => $this->timestamp($user->updated_at),
                    'km' => $this->capturedKilometers($connection, $userId),
                    'photos' => $this->photoCount($connection, $userId),
                ],
            ],
            'pagination' => $this->pagination($request, $path),
        ];
    }

    private function capturedKilometers(ConnectionInterface $connection, int $userId): string
    {
        $value = $connection->table('default_mapilio_sequence_detail')
            ->where('created_by_id', $userId)
            ->whereNull('project_key')
            ->whereNull('deleted_at')
            ->sum('length_km');

        return $this->numericString(round((float) $value, 1));
    }

    private function photoCount(ConnectionInterface $connection, int $userId): int
    {
        return $connection->table('default_mapilio_imagery')
            ->where('created_by_id', $userId)
            ->whereNull('project_key')
            ->whereNull('deleted_at')
            ->count();
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d\TH:i:s.000000\Z', $timestamp);
    }

    private function numericString(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.1F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @return Pagination
     */
    private function pagination(Request $request, string $path): array
    {
        $page = max(1, (int) $request->query('page', 1));

        return [
            'current_page' => $page,
            'first_page_url' => $this->pageUrl($path, $request, 1),
            'from' => 1,
            'last_page' => 1,
            'last_page_url' => $this->pageUrl($path, $request, 1),
            'links' => [
                [
                    'url' => null,
                    'label' => '&laquo; Previous',
                    'active' => false,
                ],
                [
                    'url' => $this->pageUrl($path, $request, 1),
                    'label' => '1',
                    'active' => true,
                ],
                [
                    'url' => null,
                    'label' => 'Next &raquo;',
                    'active' => false,
                ],
            ],
            'next_page_url' => null,
            'path' => $path,
            'per_page' => self::LEGACY_PAGINATION_SIZE,
            'prev_page_url' => null,
            'to' => 1,
            'total' => 1,
        ];
    }

    private function pageUrl(string $path, Request $request, int $page): string
    {
        $query = $request->query();
        $query['page'] = $page;

        return $path.'?'.http_build_query($query);
    }
}
