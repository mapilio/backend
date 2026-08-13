<?php

namespace App\Domain\ImageryReports\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateImageReport
{
    /**
     * Reports are accepted anonymously, so without this an unauthenticated
     * caller can fill the moderation queue with rows pointing at imagery that
     * never existed.
     *
     * Soft-deleted imagery deliberately still counts as existing. A report
     * about imagery that was removed after the reporter saw it is legitimate,
     * and rejecting it would turn a working request into an error for a real
     * user rather than for an abuser.
     */
    public function imageryExists(int $imageryId): bool
    {
        return DB::connection(config('mapilio.legacy_database_connection'))
            ->table('default_mapilio_imagery')
            ->where('id', $imageryId)
            ->exists();
    }

    /**
     * @return array{data: array{id: int, sort_order: null, created_at: string|null, created_by_id: int|null, updated_at: string|null, updated_by_id: int|null, deleted_at: null, imagery_id: int, description: string}}
     */
    public function create(int $imageryId, string $message, ?object $user): array
    {
        $now = Carbon::now();
        $createdById = $user === null ? null : (int) $user->id;

        $id = DB::connection(config('mapilio.legacy_database_connection'))
            ->table('default_image_complaint_complaint')
            ->insertGetId([
                'created_at' => $now,
                'created_by_id' => $createdById,
                'updated_at' => $now,
                'updated_by_id' => $createdById,
                'deleted_at' => null,
                'imagery_id' => $imageryId,
                'description' => $message,
            ]);

        return [
            'data' => [
                'id' => (int) $id,
                'sort_order' => null,
                'created_at' => $this->formatIsoTimestamp($now),
                'created_by_id' => $createdById,
                'updated_at' => $this->formatIsoTimestamp($now),
                'updated_by_id' => $createdById,
                'deleted_at' => null,
                'imagery_id' => $imageryId,
                'description' => $message,
            ],
        ];
    }

    private function formatIsoTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
