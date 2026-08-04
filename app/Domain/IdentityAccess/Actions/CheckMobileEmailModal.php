<?php

namespace App\Domain\IdentityAccess\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckMobileEmailModal
{
    /**
     * @return array{status: bool}
     */
    public function check(object $user): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));

        $isShown = $connection
            ->table('default_user_profile_profile')
            ->where('created_by_id', (int) $user->id)
            ->exists();

        if (! $isShown) {
            $now = Carbon::now();

            $connection
                ->table('default_user_profile_profile')
                ->insert([
                    'created_at' => $now,
                    'created_by_id' => (int) $user->id,
                    'updated_at' => $now,
                    'updated_by_id' => (int) $user->id,
                ]);
        }

        return ['status' => $isShown];
    }
}
