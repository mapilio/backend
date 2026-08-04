<?php

namespace App\Domain\ImageryReports\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateImageReport
{
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
