<?php

namespace App\Domain\IdentityAccess\Actions;

use Illuminate\Auth\AuthenticationException;
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

        $isShown = $connection->transaction(function () use ($connection, $user): bool {
            $activeUser = $connection
                ->table('default_users_users')
                ->where('id', (int) $user->id)
                ->whereNull('deleted_at')
                ->where('activated', true)
                ->where('enabled', true)
                ->lockForUpdate()
                ->first();

            if ($activeUser === null) {
                throw new AuthenticationException;
            }

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

            return $isShown;
        });

        return ['status' => $isShown];
    }
}
