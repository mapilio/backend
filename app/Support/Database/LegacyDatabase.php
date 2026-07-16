<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LegacyDatabase
{
    public static function connection(): Connection
    {
        $name = config('mapilio.legacy_database_connection');

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException('Legacy database connection is not configured.');
        }

        return DB::connection($name);
    }
}
