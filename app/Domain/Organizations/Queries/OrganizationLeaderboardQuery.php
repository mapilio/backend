<?php

namespace App\Domain\Organizations\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrganizationLeaderboardQuery
{
    public const SCORE_VERSION_SEQUENCE = 1;

    public const SCORE_VERSION_UKM = 2;

    public function get(int $scoreVersion = self::SCORE_VERSION_SEQUENCE): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $scoreVersion = $this->scoreVersion($scoreVersion);

        $rows = $connection->select($this->sql($connection, $scoreVersion));

        return collect($rows)
            ->map(fn (object $row): array => $this->mapRow($row))
            ->all();
    }

    private function scoreVersion(int $scoreVersion): int
    {
        if (! in_array($scoreVersion, [self::SCORE_VERSION_SEQUENCE, self::SCORE_VERSION_UKM], true)) {
            throw new InvalidArgumentException("'score_version' must be 1 or 2!");
        }

        return $scoreVersion;
    }

    private function sql(ConnectionInterface $connection, int $scoreVersion): string
    {
        if ($scoreVersion === self::SCORE_VERSION_UKM) {
            return $this->ukmScoreSql($connection);
        }

        return $this->sequencePointSql($connection);
    }

    private function sequencePointSql(ConnectionInterface $connection): string
    {
        $pointExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(filtered_entries.sequence_point)::numeric, 0) AS point'
            : 'ROUND(SUM(filtered_entries.sequence_point), 0) AS point';
        $lengthExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(filtered_entries.length_km)::numeric, 2) AS total_length'
            : 'ROUND(SUM(filtered_entries.length_km), 2) AS total_length';

        return sprintf(
            <<<'SQL'
WITH filtered_entries AS (
    SELECT
        entries.sequence_point,
        entries.length_km,
        organizations.organization_key,
        organizations.organization_name
    FROM default_mapilio_sequence_detail AS entries
    INNER JOIN default_organizations_organization AS organizations
        ON organizations.organization_key = entries.organization_key
    WHERE entries.deleted_at IS NULL
        AND entries.anomaly IS FALSE
        AND organizations.deleted_at IS NULL
        AND organizations.organization_key IS NOT NULL
),
image_counts AS (
    SELECT
        organization_key,
        COUNT(*) AS total_images
    FROM default_mapilio_imagery
    WHERE deleted_at IS NULL
        AND anomaly IS FALSE
        AND organization_key IS NOT NULL
    GROUP BY organization_key
)
SELECT
    filtered_entries.organization_key,
    filtered_entries.organization_name,
    %s,
    %s,
    COALESCE(image_counts.total_images, 0) AS total_images
FROM filtered_entries
LEFT JOIN image_counts
    ON image_counts.organization_key = filtered_entries.organization_key
GROUP BY filtered_entries.organization_key, filtered_entries.organization_name, image_counts.total_images
ORDER BY point DESC
SQL,
            $pointExpression,
            $lengthExpression,
        );
    }

    private function ukmScoreSql(ConnectionInterface $connection): string
    {
        $pointExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(imagery_scores.sequence_score)::numeric, 0) AS point'
            : 'ROUND(SUM(imagery_scores.sequence_score), 0) AS point';
        $lengthExpression = $connection->getDriverName() === 'pgsql'
            ? 'ROUND(SUM(filtered_entries.length_km)::numeric, 2) AS total_length'
            : 'ROUND(SUM(filtered_entries.length_km), 2) AS total_length';

        return sprintf(
            <<<'SQL'
WITH filtered_entries AS (
    SELECT
        entries.length_km,
        entries.sequence_uuid,
        organizations.organization_key,
        organizations.organization_name
    FROM default_mapilio_sequence_detail AS entries
    INNER JOIN default_organizations_organization AS organizations
        ON organizations.organization_key = entries.organization_key
    WHERE entries.deleted_at IS NULL
        AND entries.anomaly IS FALSE
        AND organizations.deleted_at IS NULL
        AND organizations.organization_key IS NOT NULL
),
scoring_sequences AS (
    SELECT DISTINCT sequence_uuid
    FROM filtered_entries
),
imagery_scores AS (
    SELECT
        images.sequence_uuid,
        SUM(images.ukm_score) AS sequence_score
    FROM default_mapilio_imagery AS images
    INNER JOIN scoring_sequences
        ON scoring_sequences.sequence_uuid = images.sequence_uuid
    WHERE images.deleted_at IS NULL
        AND images.anomaly IS FALSE
    GROUP BY images.sequence_uuid
),
image_counts AS (
    SELECT
        organization_key,
        COUNT(*) AS total_images
    FROM default_mapilio_imagery
    WHERE deleted_at IS NULL
        AND anomaly IS FALSE
        AND organization_key IS NOT NULL
    GROUP BY organization_key
)
SELECT
    filtered_entries.organization_key,
    filtered_entries.organization_name,
    %s,
    %s,
    COALESCE(image_counts.total_images, 0) AS total_images
FROM filtered_entries
LEFT JOIN imagery_scores
    ON imagery_scores.sequence_uuid = filtered_entries.sequence_uuid
LEFT JOIN image_counts
    ON image_counts.organization_key = filtered_entries.organization_key
GROUP BY filtered_entries.organization_key, filtered_entries.organization_name, image_counts.total_images
ORDER BY point DESC
SQL,
            $pointExpression,
            $lengthExpression,
        );
    }

    private function mapRow(object $row): array
    {
        return [
            'organization_key' => $row->organization_key === null ? null : (string) $row->organization_key,
            'organization_name' => $row->organization_name === null ? null : (string) $row->organization_name,
            'point' => $row->point === null ? null : number_format((float) $row->point, 0, '.', ''),
            'total_length' => $row->total_length === null ? null : number_format((float) $row->total_length, 2, '.', ''),
            'total_images' => (int) $row->total_images,
        ];
    }
}
