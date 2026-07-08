<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LeaderboardQuery
{
    /**
     * @throws ValidationException
     */
    public function get(array $filters = [], ?int $limit = null): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $userId = $this->optionalUserId($filters['user_id'] ?? null);
        $dateWindow = $this->dateWindow($filters);

        $rows = $connection->select(
            $this->sql($connection, $userId, $dateWindow, $limit),
            $this->bindings($userId, $dateWindow),
        );

        return collect($rows)
            ->map(fn (object $row): array => $this->mapRow($row))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function forUser(int $userId): array
    {
        return $this->get(['user_id' => $userId]);
    }

    private function optionalUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException("'user_id' must be an integer!");
        }

        return (int) $value;
    }

    /**
     * @return array{0: string, 1: string}|null
     *
     * @throws ValidationException
     */
    private function dateWindow(array $filters): ?array
    {
        if (empty($filters['start_at']) || empty($filters['finish_at'])) {
            return null;
        }

        Validator::make($filters, [
            'start_at' => ['required', 'date'],
            'finish_at' => ['required', 'date'],
        ])->validate();

        $startAt = Carbon::parse($filters['start_at'])->startOfDay();
        $finishAt = Carbon::parse($filters['finish_at'])->endOfDay();
        $from = $startAt->lessThanOrEqualTo($finishAt) ? $startAt : $finishAt->copy()->startOfDay();
        $to = $startAt->lessThanOrEqualTo($finishAt) ? $finishAt : $startAt->copy()->endOfDay();

        return [
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param  array{0: string, 1: string}|null  $dateWindow
     */
    private function sql(ConnectionInterface $connection, ?int $userId, ?array $dateWindow, ?int $limit): string
    {
        $sequenceWhere = [
            'entries.deleted_at IS NULL',
            'entries.anomaly IS FALSE',
            'users.deleted_at IS NULL',
        ];
        $imageWhere = [
            'deleted_at IS NULL',
            'anomaly IS FALSE',
        ];

        if ($dateWindow !== null) {
            $sequenceWhere[] = 'entries.created_at BETWEEN ? AND ?';
            $imageWhere[] = 'created_at BETWEEN ? AND ?';
        }

        if ($userId !== null) {
            $sequenceWhere[] = 'entries.created_by_id = ?';
            $imageWhere[] = 'created_by_id = ?';
        }

        $outerWhere = [
            'sequence_scores.point IS NOT NULL',
            'sequence_scores.point != 0',
        ];
        $excludedRoleSlugs = $this->excludedRoleSlugs();

        if ($excludedRoleSlugs !== []) {
            $placeholders = implode(', ', array_fill(0, count($excludedRoleSlugs), '?'));
            $outerWhere[] = "NOT EXISTS (
                SELECT 1
                FROM default_users_users_roles AS excluded_user_roles
                INNER JOIN default_users_roles AS excluded_roles
                    ON excluded_roles.id = excluded_user_roles.related_id
                WHERE excluded_user_roles.entry_id = sequence_scores.id
                    AND excluded_roles.slug IN ($placeholders)
            )";
        }

        $pointExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(entries.sequence_point)::numeric, 0) AS point'
            : 'ROUND(SUM(entries.sequence_point), 0) AS point';
        $lengthExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(entries.length_km)::numeric, 2) AS total_length'
            : 'ROUND(SUM(entries.length_km), 2) AS total_length';
        $rolesExpression = $connection->getDriverName() === 'pgsql'
            ? 'ARRAY_AGG(roles.slug) AS roles'
            : 'GROUP_CONCAT(roles.slug) AS roles';
        $limit = max(1, min($limit ?? (int) config('mapilio.leaderboard.limit', 30), 100));

        return sprintf(
            <<<'SQL'
WITH sequence_scores AS (
    SELECT
        users.id,
        users.username,
        users.display_name,
        users.user_profile_photo,
        %s,
        %s
    FROM default_mapilio_sequence_detail AS entries
    INNER JOIN default_users_users AS users
        ON users.id = entries.created_by_id
    WHERE %s
    GROUP BY users.id, users.username, users.display_name, users.user_profile_photo
),
image_counts AS (
    SELECT
        created_by_id,
        COUNT(*) AS total_images
    FROM default_mapilio_imagery
    WHERE %s
    GROUP BY created_by_id
),
user_roles AS (
    SELECT
        user_roles.entry_id,
        %s
    FROM default_users_users_roles AS user_roles
    LEFT JOIN default_users_roles AS roles
        ON roles.id = user_roles.related_id
    GROUP BY user_roles.entry_id
)
SELECT
    sequence_scores.id,
    sequence_scores.username,
    sequence_scores.display_name,
    sequence_scores.user_profile_photo,
    sequence_scores.point,
    sequence_scores.total_length,
    COALESCE(image_counts.total_images, 0) AS total_images,
    user_roles.roles
FROM sequence_scores
LEFT JOIN image_counts
    ON image_counts.created_by_id = sequence_scores.id
LEFT JOIN user_roles
    ON user_roles.entry_id = sequence_scores.id
WHERE %s
ORDER BY sequence_scores.point DESC
LIMIT %d
SQL,
            $pointExpression,
            $lengthExpression,
            implode(' AND ', $sequenceWhere),
            implode(' AND ', $imageWhere),
            $rolesExpression,
            implode(' AND ', $outerWhere),
            $limit,
        );
    }

    /**
     * @param  array{0: string, 1: string}|null  $dateWindow
     */
    private function bindings(?int $userId, ?array $dateWindow): array
    {
        $bindings = [];

        if ($dateWindow !== null) {
            array_push($bindings, $dateWindow[0], $dateWindow[1]);
        }

        if ($userId !== null) {
            $bindings[] = $userId;
        }

        if ($dateWindow !== null) {
            array_push($bindings, $dateWindow[0], $dateWindow[1]);
        }

        if ($userId !== null) {
            $bindings[] = $userId;
        }

        return array_merge($bindings, $this->excludedRoleSlugs());
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'username' => $row->username === null ? null : (string) $row->username,
            'display_name' => $row->display_name === null ? null : (string) $row->display_name,
            'user_profile_photo' => $row->user_profile_photo === null ? null : (string) $row->user_profile_photo,
            'point' => number_format((float) $row->point, 0, '.', ''),
            'total_length' => number_format((float) $row->total_length, 2, '.', ''),
            'total_images' => (int) $row->total_images,
            'roles' => $this->normaliseRoles($row->roles ?? null),
        ];
    }

    private function normaliseRoles(mixed $roles): ?string
    {
        if ($roles === null || $roles === '') {
            return null;
        }

        $roles = (string) $roles;

        if (str_starts_with($roles, '{')) {
            return $roles;
        }

        return '{'.$roles.'}';
    }

    private function excludedRoleSlugs(): array
    {
        return collect(config('mapilio.leaderboard.excluded_role_slugs', []))
            ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
            ->map(fn (string $slug): string => trim($slug))
            ->values()
            ->all();
    }
}
