<?php

namespace App\Domain\Projects\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateMobileProjectJob
{
    public function create(int $projectId, object $user): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $project = $connection
            ->table('default_projects_project')
            ->where('id', $projectId)
            ->whereNull('deleted_at')
            ->first();

        if ($project === null) {
            throw MobileProjectJobException::projectNotFound();
        }

        if (! (bool) $project->is_marketplace) {
            throw MobileProjectJobException::projectNotEligible();
        }

        $existingJob = $connection
            ->table('default_projects_job')
            ->where('project_id', $projectId)
            ->where('assign_id', (int) $user->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($existingJob) {
            throw MobileProjectJobException::alreadyMember();
        }

        $now = Carbon::now();

        $connection
            ->table('default_projects_job')
            ->insert([
                'created_at' => $now,
                'created_by_id' => (int) $user->id,
                'updated_at' => $now,
                'updated_by_id' => (int) $user->id,
                'deleted_at' => null,
                'project_key' => $project->project_key,
                'project_id' => (int) $project->id,
                'assign_id' => (int) $user->id,
            ]);

        return ['data' => true];
    }
}
