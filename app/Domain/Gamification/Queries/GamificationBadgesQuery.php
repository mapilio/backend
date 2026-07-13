<?php

namespace App\Domain\Gamification\Queries;

use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GamificationBadgesQuery
{
    public function get(Request $request, int $userId, LeaderboardQuery $leaderboardQuery): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));

        if (! $this->userExists($connection, $userId)) {
            return [];
        }

        $locale = $this->locale($request);
        $assetRoot = $request->getSchemeAndHttpHost();
        $ownedBadgeIds = $this->ownedBadgeIds($connection, $userId);
        $levels = $this->levels($connection);
        $point = $this->point($leaderboardQuery, $userId);
        $badges = $this->badges($connection, $locale);
        $disabledImages = $this->disabledImagePayloads($connection, $badges, $locale);
        $currentLevelId = $this->currentLevelId($connection, $userId);

        return [
            'badges' => $badges
                ->map(fn (object $badge): array => $this->badgePayload(
                    $badge,
                    $ownedBadgeIds,
                    $levels,
                    $assetRoot,
                    $disabledImages,
                ))
                ->all(),
            'point' => $point,
            'next' => [
                'badge' => $this->nextBadge($badges, $currentLevelId, $assetRoot),
                'percentage' => $this->legacyPercentage($point, $badges, $currentLevelId),
            ],
        ];
    }

    private function userExists(ConnectionInterface $connection, int $userId): bool
    {
        return $connection
            ->table('default_users_users')
            ->where('id', $userId)
            ->exists();
    }

    /**
     * @return array<int, true>
     */
    private function ownedBadgeIds(ConnectionInterface $connection, int $userId): array
    {
        return $connection
            ->table('default_gamification_user_badge')
            ->where('user_id', $userId)
            ->pluck('badge_id')
            ->mapWithKeys(fn (mixed $badgeId): array => [(int) $badgeId => true])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function levels(ConnectionInterface $connection): array
    {
        return $connection
            ->table('default_gamification_level')
            ->pluck('xp', 'id')
            ->mapWithKeys(fn (mixed $xp, mixed $id): array => [(int) $id => (int) $xp])
            ->all();
    }

    private function currentLevelId(ConnectionInterface $connection, int $userId): int
    {
        $levelId = $connection
            ->table('default_gamification_user_level')
            ->where('user_id', $userId)
            ->value('level_id');

        return $levelId === null ? 0 : (int) $levelId;
    }

    private function point(LeaderboardQuery $leaderboardQuery, int $userId): int|string
    {
        $leaderboard = $leaderboardQuery->forUser($userId);

        return $leaderboard[0]['point'] ?? 0;
    }

    private function badges(ConnectionInterface $connection, string $locale)
    {
        $query = $connection
            ->table('default_gamification_badge as badge')
            ->leftJoin('default_gamification_badge_translations as translations', function ($join) use ($locale): void {
                $join->on('translations.entry_id', '=', 'badge.id')
                    ->where('translations.locale', '=', $locale);
            })
            ->select([
                'badge.id',
                'badge.sort_order',
                'badge.created_at',
                'badge.created_by_id',
                'badge.updated_at',
                'badge.updated_by_id',
                'badge.slug',
                'badge.image_id',
                'badge.available_level',
                'badge.is_custom',
                'badge.color_code',
                'badge.disabled_image_id',
                'translations.title',
                'translations.info',
            ]);

        if ($connection->getDriverName() === 'sqlite') {
            $query->orderBy('badge.rowid');
        }

        return $query->get();
    }

    /**
     * @param  array<int, true>  $ownedBadgeIds
     * @param  array<int, int>  $levels
     */
    private function badgePayload(
        object $badge,
        array $ownedBadgeIds,
        array $levels,
        string $assetRoot,
        array $disabledImages,
    ): array {
        $enabled = isset($ownedBadgeIds[(int) $badge->id]);

        $payload = $this->baseBadgePayload($badge);
        $payload['enable'] = $enabled;
        $payload['icon'] = $assetRoot;
        $payload['point'] = $levels[(int) $badge->available_level] ?? 0;
        $payload['title'] = $badge->title;
        $payload['info'] = $badge->info;

        if (! $enabled) {
            $disabledImageId = $this->nullableInt($badge->disabled_image_id);
            $payload['disabled_image'] = $disabledImageId === null ? null : ($disabledImages[$disabledImageId] ?? null);
        }

        return $payload;
    }

    private function nextBadge($badges, int $currentLevelId, string $assetRoot): ?array
    {
        $badge = $badges
            ->filter(fn (object $badge): bool => (int) $badge->available_level >= $currentLevelId)
            ->sortBy('available_level')
            ->first();

        if ($badge === null) {
            return null;
        }

        $payload = $this->baseBadgePayload($badge);
        $payload['icon'] = $assetRoot;
        $payload['title'] = $badge->title;
        $payload['info'] = $badge->info;

        return $payload;
    }

    private function legacyPercentage(int|string $point, $badges, int $currentLevelId): string|int
    {
        $badge = $badges
            ->filter(fn (object $badge): bool => (int) $badge->available_level >= $currentLevelId)
            ->sortBy('available_level')
            ->first();

        if ($badge === null || (int) $badge->available_level === 0) {
            return 0;
        }

        return number_format(((float) $point) / (int) $badge->available_level, 0);
    }

    private function baseBadgePayload(object $badge): array
    {
        return [
            'id' => (int) $badge->id,
            'sort_order' => $this->nullableInt($badge->sort_order),
            'created_at' => $this->timestamp($badge->created_at),
            'created_by_id' => $this->nullableInt($badge->created_by_id),
            'updated_at' => $this->timestamp($badge->updated_at),
            'updated_by_id' => $this->nullableInt($badge->updated_by_id),
            'slug' => $badge->slug,
            'image_id' => $this->nullableInt($badge->image_id),
            'available_level' => $this->nullableInt($badge->available_level),
            'is_custom' => (bool) $badge->is_custom,
            'color_code' => $badge->color_code,
            'disabled_image_id' => $this->nullableInt($badge->disabled_image_id),
        ];
    }

    private function disabledImagePayloads(ConnectionInterface $connection, $badges, string $locale): array
    {
        $fileIds = $badges
            ->pluck('disabled_image_id')
            ->filter(fn (mixed $fileId): bool => $fileId !== null)
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->unique()
            ->values()
            ->all();

        if ($fileIds === []) {
            return [];
        }

        $files = $connection
            ->table('default_files_files')
            ->whereIn('id', $fileIds)
            ->get()
            ->keyBy(fn (object $file): int => (int) $file->id);

        $folderIds = $files
            ->pluck('folder_id')
            ->filter(fn (mixed $folderId): bool => $folderId !== null)
            ->map(fn (mixed $folderId): int => (int) $folderId)
            ->unique()
            ->values()
            ->all();
        $diskIds = $files
            ->pluck('disk_id')
            ->filter(fn (mixed $diskId): bool => $diskId !== null)
            ->map(fn (mixed $diskId): int => (int) $diskId)
            ->unique()
            ->values()
            ->all();

        $folders = $this->folderPayloads($connection, $folderIds, $locale);
        $disks = $this->diskPayloads($connection, $diskIds, $locale);

        return $files
            ->mapWithKeys(fn (object $file): array => [
                (int) $file->id => $this->filePayload($file, $folders, $disks),
            ])
            ->all();
    }

    private function filePayload(object $file, array $folders, array $disks): array
    {
        $folderId = $this->nullableInt($file->folder_id);
        $diskId = $this->nullableInt($file->disk_id);
        $folder = $folderId === null ? null : ($folders[$folderId] ?? null);
        $disk = $diskId === null ? null : ($disks[$diskId] ?? null);
        $path = $folder === null ? (string) $file->name : $folder['slug'].'/'.$file->name;

        return [
            'id' => (int) $file->id,
            'sort_order' => $this->nullableInt($file->sort_order),
            'created_at' => $this->timestamp($file->created_at),
            'created_by_id' => $this->nullableInt($file->created_by_id),
            'updated_at' => $this->timestamp($file->updated_at),
            'updated_by_id' => $this->nullableInt($file->updated_by_id),
            'deleted_at' => $this->timestamp($file->deleted_at),
            'name' => $file->name,
            'disk_id' => $this->nullableInt($file->disk_id),
            'folder_id' => $this->nullableInt($file->folder_id),
            'extension' => $file->extension,
            'size' => $this->nullableInt($file->size),
            'mime_type' => $file->mime_type,
            'entry_id' => $this->nullableInt($file->entry_id),
            'entry_type' => $file->entry_type,
            'keywords' => $file->keywords,
            'height' => $file->height,
            'width' => $file->width,
            'alt_text' => $file->alt_text,
            'title' => $file->title,
            'caption' => $file->caption,
            'description' => $file->description,
            'str_id' => $file->str_id,
            'disk' => $disk,
            'folder' => $folder,
            'entry' => null,
            'path' => $path,
            'location' => ($disk['slug'] ?? '').'://'.$path,
        ];
    }

    private function diskPayloads(ConnectionInterface $connection, array $diskIds, string $locale): array
    {
        if ($diskIds === []) {
            return [];
        }

        return $connection
            ->table('default_files_disks as disk')
            ->leftJoin('default_files_disks_translations as translations', function ($join) use ($locale): void {
                $join->on('translations.entry_id', '=', 'disk.id')
                    ->where('translations.locale', '=', $locale);
            })
            ->whereIn('disk.id', $diskIds)
            ->select([
                'disk.id',
                'disk.sort_order',
                'disk.created_at',
                'disk.created_by_id',
                'disk.updated_at',
                'disk.updated_by_id',
                'disk.deleted_at',
                'disk.slug',
                'disk.adapter',
                'translations.name',
                'translations.description',
            ])
            ->get()
            ->mapWithKeys(fn (object $disk): array => [
                (int) $disk->id => [
                    'id' => (int) $disk->id,
                    'sort_order' => $this->nullableInt($disk->sort_order),
                    'created_at' => $this->timestamp($disk->created_at),
                    'created_by_id' => $this->nullableInt($disk->created_by_id),
                    'updated_at' => $this->timestamp($disk->updated_at),
                    'updated_by_id' => $this->nullableInt($disk->updated_by_id),
                    'deleted_at' => $this->timestamp($disk->deleted_at),
                    'slug' => $disk->slug,
                    'adapter' => $disk->adapter,
                    'name' => $disk->name,
                    'description' => $disk->description,
                ],
            ])
            ->all();
    }

    private function folderPayloads(ConnectionInterface $connection, array $folderIds, string $locale): array
    {
        if ($folderIds === []) {
            return [];
        }

        return $connection
            ->table('default_files_folders as folder')
            ->leftJoin('default_files_folders_translations as translations', function ($join) use ($locale): void {
                $join->on('translations.entry_id', '=', 'folder.id')
                    ->where('translations.locale', '=', $locale);
            })
            ->whereIn('folder.id', $folderIds)
            ->select([
                'folder.id',
                'folder.sort_order',
                'folder.created_at',
                'folder.created_by_id',
                'folder.updated_at',
                'folder.updated_by_id',
                'folder.deleted_at',
                'folder.disk_id',
                'folder.slug',
                'folder.allowed_types',
                'folder.str_id',
                'translations.name',
                'translations.description',
            ])
            ->get()
            ->mapWithKeys(fn (object $folder): array => [
                (int) $folder->id => [
                    'id' => (int) $folder->id,
                    'sort_order' => $this->nullableInt($folder->sort_order),
                    'created_at' => $this->timestamp($folder->created_at),
                    'created_by_id' => $this->nullableInt($folder->created_by_id),
                    'updated_at' => $this->timestamp($folder->updated_at),
                    'updated_by_id' => $this->nullableInt($folder->updated_by_id),
                    'deleted_at' => $this->timestamp($folder->deleted_at),
                    'disk_id' => $this->nullableInt($folder->disk_id),
                    'slug' => $folder->slug,
                    'allowed_types' => $folder->allowed_types,
                    'str_id' => $folder->str_id,
                    'name' => $folder->name,
                    'description' => $folder->description,
                ],
            ])
            ->all();
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return date('Y-m-d\TH:i:s.000000\Z', strtotime((string) $value));
    }

    private function locale(Request $request): string
    {
        $locale = $request->query('locale', app()->getLocale());

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }
}
