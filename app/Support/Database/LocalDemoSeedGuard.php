<?php

namespace App\Support\Database;

use RuntimeException;

final class LocalDemoSeedGuard
{
    /**
     * @param  mixed  $enabled  Kept mixed so malformed cached configuration fails closed.
     */
    public static function assertAllowed(string $environment, mixed $enabled, string $driver): void
    {
        if ($enabled !== true) {
            throw new RuntimeException('Local demo seeding must be explicitly enabled.');
        }

        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new RuntimeException('Local demo seeding is restricted to local and testing environments.');
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException('Local demo seeding is restricted to SQLite databases.');
        }
    }
}
