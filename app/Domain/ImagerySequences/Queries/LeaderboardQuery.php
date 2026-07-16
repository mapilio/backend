<?php

namespace App\Domain\ImagerySequences\Queries;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LeaderboardQuery
{
    public const SCORE_VERSION_SEQUENCE = 1;

    public const SCORE_VERSION_IMAGE = 2;

    /**
     * @throws ValidationException
     */
    public function get(array $filters = [], ?int $limit = null, int $scoreVersion = self::SCORE_VERSION_SEQUENCE): array
    {
        $connection = LegacyDatabase::connection();
        $userId = $this->optionalUserId($filters['user_id'] ?? null);
        $dateWindow = $this->dateWindow($filters);
        $scoreVersion = $this->scoreVersion($scoreVersion);

        $rows = $connection->select(
            $this->sql($connection, $userId, $dateWindow, $limit, $scoreVersion),
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

    private function scoreVersion(int $scoreVersion): int
    {
        if (! in_array($scoreVersion, [self::SCORE_VERSION_SEQUENCE, self::SCORE_VERSION_IMAGE], true)) {
            throw new InvalidArgumentException("'score_version' must be 1 or 2!");
        }

        return $scoreVersion;
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
    private function sql(
        Connection $connection,
        ?int $userId,
        ?array $dateWindow,
        ?int $limit,
        int $scoreVersion,
    ): string {
        if ($scoreVersion === self::SCORE_VERSION_IMAGE) {
            return $this->imageScoreSql($connection, $userId, $dateWindow, $limit);
        }

        return $this->sequencePointSql($connection, $userId, $dateWindow, $limit);
    }

    /**
     * @param  array{0: string, 1: string}|null  $dateWindow
     */
    private function sequencePointSql(Connection $connection, ?int $userId, ?array $dateWindow, ?int $limit): string
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
        $outerWhere = array_merge($outerWhere, $this->roleFilters());

        $pointExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(entries.sequence_point)::numeric, 0) AS point'
            : 'ROUND(SUM(entries.sequence_point), 0) AS point';
        $lengthExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(entries.length_km)::numeric, 2) AS total_length'
            : 'ROUND(SUM(entries.length_km), 2) AS total_length';
        $rolesExpression = $this->rolesSelectExpression($connection);
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
)
SELECT
    sequence_scores.id,
    sequence_scores.username,
    sequence_scores.display_name,
    sequence_scores.user_profile_photo,
    sequence_scores.point,
    sequence_scores.total_length,
    COALESCE(image_counts.total_images, 0) AS total_images,
    %s
FROM sequence_scores
LEFT JOIN image_counts
    ON image_counts.created_by_id = sequence_scores.id
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
    private function imageScoreSql(Connection $connection, ?int $userId, ?array $dateWindow, ?int $limit): string
    {
        $sequenceWhere = [
            'entries.deleted_at IS NULL',
            'entries.anomaly IS FALSE',
            'users.deleted_at IS NULL',
        ];
        $imageCountWhere = [
            'counted_images.deleted_at IS NULL',
            'counted_images.anomaly IS FALSE',
        ];

        if ($dateWindow !== null) {
            $sequenceWhere[] = 'entries.created_at BETWEEN ? AND ?';
            $imageCountWhere[] = 'counted_images.created_at BETWEEN ? AND ?';
        }

        if ($userId !== null) {
            $sequenceWhere[] = 'entries.created_by_id = ?';
            $imageCountWhere[] = 'counted_images.created_by_id = ?';
        }

        $outerWhere = [
            'sequence_scores.point IS NOT NULL',
            'sequence_scores.point != 0',
        ];
        $outerWhere = array_merge($outerWhere, $this->roleFilters());

        $pointExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(imagery_scores.sequence_score)::numeric, 0) AS point'
            : 'ROUND(SUM(imagery_scores.sequence_score), 0) AS point';
        $lengthExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(entries.length_km)::numeric, 2) AS total_length'
            : 'ROUND(SUM(entries.length_km), 2) AS total_length';
        $imageScoreExpression = 'SUM(img.ukm_score + img.gps_score + img.time_score + img.distance_score) AS sequence_score';
        $rolesExpression = $this->rolesSelectExpression($connection);
        $limit = max(1, min($limit ?? (int) config('mapilio.leaderboard.limit', 30), 100));

        return sprintf(
            <<<'SQL'
WITH filtered_entries AS (
    SELECT
        entries.sequence_point,
        entries.length_km,
        entries.sequence_uuid,
        users.id,
        users.username,
        users.display_name,
        users.user_profile_photo
    FROM default_mapilio_sequence_detail AS entries
    INNER JOIN default_users_users AS users
        ON users.id = entries.created_by_id
    WHERE %s
),
scoring_sequences AS (
    SELECT DISTINCT sequence_uuid
    FROM filtered_entries
),
imagery_scores AS (
    SELECT
        img.sequence_uuid,
        %s
    FROM default_mapilio_imagery AS img
    INNER JOIN scoring_sequences
        ON scoring_sequences.sequence_uuid = img.sequence_uuid
    WHERE img.deleted_at IS NULL
        AND img.anomaly IS FALSE
    GROUP BY img.sequence_uuid
),
sequence_scores AS (
    SELECT
        entries.id,
        entries.username,
        entries.display_name,
        entries.user_profile_photo,
        %s,
        %s
    FROM filtered_entries AS entries
    LEFT JOIN imagery_scores
        ON imagery_scores.sequence_uuid = entries.sequence_uuid
    GROUP BY entries.id, entries.username, entries.display_name, entries.user_profile_photo
),
image_counts AS (
    SELECT
        counted_images.created_by_id,
        COUNT(*) AS total_images
    FROM default_mapilio_imagery AS counted_images
    WHERE %s
    GROUP BY counted_images.created_by_id
)
SELECT
    sequence_scores.id,
    sequence_scores.username,
    sequence_scores.display_name,
    sequence_scores.user_profile_photo,
    sequence_scores.point,
    sequence_scores.total_length,
    COALESCE(image_counts.total_images, 0) AS total_images,
    %s
FROM sequence_scores
LEFT JOIN image_counts
    ON image_counts.created_by_id = sequence_scores.id
WHERE %s
ORDER BY sequence_scores.point DESC
LIMIT %d
SQL,
            implode(' AND ', $sequenceWhere),
            $imageScoreExpression,
            $pointExpression,
            $lengthExpression,
            implode(' AND ', $imageCountWhere),
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

        return array_merge($bindings, $this->roleFilterBindings());
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

    private function publicRoleSlugs(): array
    {
        return collect(config('mapilio.leaderboard.public_role_slugs', []))
            ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
            ->map(fn (string $slug): string => trim($slug))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function roleFilters(): array
    {
        $filters = [];
        $excludedRoleSlugs = $this->excludedRoleSlugs();
        $publicRoleSlugs = $this->publicRoleSlugs();

        if ($excludedRoleSlugs !== []) {
            $placeholders = implode(', ', array_fill(0, count($excludedRoleSlugs), '?'));
            $filters[] = "NOT EXISTS (
                SELECT 1
                FROM default_users_users_roles AS excluded_user_roles
                INNER JOIN default_users_roles AS excluded_roles
                    ON excluded_roles.id = excluded_user_roles.related_id
                WHERE excluded_user_roles.entry_id = sequence_scores.id
                    AND excluded_roles.slug IN ($placeholders)
            )";
        }

        if ($publicRoleSlugs !== []) {
            $placeholders = implode(', ', array_fill(0, count($publicRoleSlugs), '?'));
            $filters[] = "NOT EXISTS (
                SELECT 1
                FROM default_users_users_roles AS private_user_roles
                INNER JOIN default_users_roles AS private_roles
                    ON private_roles.id = private_user_roles.related_id
                WHERE private_user_roles.entry_id = sequence_scores.id
                    AND private_roles.slug NOT IN ($placeholders)
            )";
        }

        return $filters;
    }

    private function rolesSelectExpression(Connection $connection): string
    {
        if ($connection->getDriverName() === 'pgsql') {
            return '(SELECT ARRAY_AGG(selected_roles.slug)
                FROM default_users_users_roles AS selected_user_roles
                LEFT JOIN default_users_roles AS selected_roles
                    ON selected_roles.id = selected_user_roles.related_id
                WHERE selected_user_roles.entry_id = sequence_scores.id) AS roles';
        }

        return '(SELECT GROUP_CONCAT(selected_roles.slug)
            FROM default_users_users_roles AS selected_user_roles
            LEFT JOIN default_users_roles AS selected_roles
                ON selected_roles.id = selected_user_roles.related_id
            WHERE selected_user_roles.entry_id = sequence_scores.id) AS roles';
    }

    /**
     * @return list<string>
     */
    private function roleFilterBindings(): array
    {
        return array_merge($this->excludedRoleSlugs(), $this->publicRoleSlugs());
    }
}
