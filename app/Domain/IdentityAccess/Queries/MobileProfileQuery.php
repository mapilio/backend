<?php

namespace App\Domain\IdentityAccess\Queries;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

class MobileProfileQuery
{
    public function get(int $userId): ?array
    {
        $connection = LegacyDatabase::connection();
        $user = $connection->table('default_users_users')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->first([
                'id',
                'username',
                'email',
                'user_profile_photo',
                'display_name',
                'str_id',
                'hidden_profile',
                'user_bio',
                'created_at',
                'updated_at',
                'shape_limit',
            ]);

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'user_profile_photo' => $user->user_profile_photo ?: config('mapilio.mobile_auth.default_profile_photo_url'),
            'display_name' => $user->display_name,
            'str_id' => $user->str_id,
            'hidden_profile' => $user->hidden_profile,
            'user_bio' => $user->user_bio,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'shape_limit' => $user->shape_limit,
            'isAdmin' => $this->isAdmin($connection, $userId),
            'sequences' => $this->sequenceCount($connection, $userId),
            'photos' => $this->photoCount($connection, $userId),
            'meters' => $this->capturedMeters($connection, $userId),
            'score' => $this->score($connection, $userId),
        ];
    }

    private function isAdmin(Connection $connection, int $userId): bool
    {
        if (! $this->hasTables($connection, ['default_users_users_roles', 'default_users_roles'])) {
            return false;
        }

        return $connection->table('default_users_users_roles as user_roles')
            ->join('default_users_roles as roles', 'roles.id', '=', 'user_roles.related_id')
            ->where('user_roles.entry_id', $userId)
            ->where('roles.slug', 'admin')
            ->exists();
    }

    private function sequenceCount(Connection $connection, int $userId): int
    {
        if (! $this->hasTables($connection, ['default_mapilio_imagery'])) {
            return 0;
        }

        return $connection->query()
            ->fromSub(
                $connection->table('default_mapilio_imagery')
                    ->selectRaw('MIN(id) AS min_id')
                    ->where('created_by_id', $userId)
                    ->whereNull('project_key')
                    ->whereNull('deleted_at')
                    ->groupBy('sequence_uuid'),
                'sequences',
            )
            ->count();
    }

    private function photoCount(Connection $connection, int $userId): int
    {
        if (! $this->hasTables($connection, ['default_mapilio_imagery'])) {
            return 0;
        }

        return $connection->table('default_mapilio_imagery')
            ->where('created_by_id', $userId)
            ->whereNull('project_key')
            ->whereNull('deleted_at')
            ->count();
    }

    private function capturedMeters(Connection $connection, int $userId): string
    {
        if (! $this->hasTables($connection, ['default_mapilio_sequence_detail'])) {
            return '0';
        }

        $value = $connection->table('default_mapilio_sequence_detail')
            ->where('created_by_id', $userId)
            ->whereNull('deleted_at')
            ->sum('length_km');

        return number_format(round((float) $value, 3), 3, '.', '');
    }

    private function score(Connection $connection, int $userId): string
    {
        if (! $this->hasTables($connection, ['default_mapilio_sequence_detail'])) {
            return '0';
        }

        $value = $connection->table('default_mapilio_sequence_detail')
            ->where('created_by_id', $userId)
            ->whereNull('deleted_at')
            ->sum('sequence_point');

        return number_format(round((float) $value, 0), 0, '.', '');
    }

    /**
     * @param  list<string>  $tables
     */
    private function hasTables(Connection $connection, array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::connection($connection->getName())->hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
