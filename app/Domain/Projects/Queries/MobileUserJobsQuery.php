<?php

namespace App\Domain\Projects\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type MobileUserJobsRow array{
 *     id: int,
 *     sort_order: int|null,
 *     created_at: string|null,
 *     created_by_id: int|null,
 *     updated_at: string|null,
 *     updated_by_id: int|null,
 *     deleted_at: string|null,
 *     project_id: int|null,
 *     project_key: string|null,
 *     assign_id: int|null,
 *     user_detail: array{array{
 *         id: int,
 *         username: string|null,
 *         email: string|null,
 *         display_name: string|null
 *     }},
 *     project_detail: array{
 *         marketplace_name: string|null,
 *         marketplace_description: string|null,
 *         project_organization_key: string|null,
 *         project_key: string|null
 *     }
 * }
 */
class MobileUserJobsQuery
{
    /**
     * @return array{data: list<MobileUserJobsRow>}
     */
    public function get(object $user): array
    {
        $rows = DB::connection(config('mapilio.legacy_database_connection'))
            ->table('default_projects_job as job')
            ->leftJoin('default_projects_project as project', 'project.id', '=', 'job.project_id')
            ->where('job.assign_id', (int) $user->id)
            ->whereNull('job.deleted_at')
            ->whereNull('project.deleted_at')
            ->select([
                'job.id',
                'job.sort_order',
                'job.created_at',
                'job.created_by_id',
                'job.updated_at',
                'job.updated_by_id',
                'job.deleted_at',
                'job.project_id',
                'job.project_key',
                'job.assign_id',
                'project.marketplace_name',
                'project.marketplace_description',
                'project.project_organization_key',
                'project.project_key as detail_project_key',
            ])
            ->orderBy('job.sort_order')
            ->orderBy('job.id')
            ->get()
            ->map(fn (object $row): array => $this->row($row, $user))
            ->all();

        return ['data' => $rows];
    }

    /**
     * @return MobileUserJobsRow
     */
    private function row(object $row, object $user): array
    {
        return [
            'id' => (int) $row->id,
            'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
            'created_at' => $this->formatIsoTimestamp($row->created_at),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'updated_at' => $this->formatIsoTimestamp($row->updated_at),
            'updated_by_id' => $row->updated_by_id === null ? null : (int) $row->updated_by_id,
            'deleted_at' => $this->formatIsoTimestamp($row->deleted_at),
            'project_id' => $row->project_id === null ? null : (int) $row->project_id,
            'project_key' => $row->project_key === null ? null : (string) $row->project_key,
            'assign_id' => $row->assign_id === null ? null : (int) $row->assign_id,
            'user_detail' => [
                [
                    'id' => (int) $user->id,
                    'username' => $user->username === null ? null : (string) $user->username,
                    'email' => $user->email === null ? null : (string) $user->email,
                    'display_name' => $user->display_name === null ? null : (string) $user->display_name,
                ],
            ],
            'project_detail' => [
                'marketplace_name' => $row->marketplace_name === null ? null : (string) $row->marketplace_name,
                'marketplace_description' => $row->marketplace_description === null ? null : (string) $row->marketplace_description,
                'project_organization_key' => $row->project_organization_key === null ? null : (string) $row->project_organization_key,
                'project_key' => $row->detail_project_key === null ? null : (string) $row->detail_project_key,
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
