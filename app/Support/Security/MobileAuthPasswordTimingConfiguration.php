<?php

namespace App\Support\Security;

use Illuminate\Contracts\Hashing\Hasher;
use RuntimeException;
use Throwable;

final class MobileAuthPasswordTimingConfiguration
{
    public static function assertSafe(mixed $dummyPasswordHash, Hasher $hasher): void
    {
        if (! is_string($dummyPasswordHash) || trim($dummyPasswordHash) === '') {
            throw self::invalidConfiguration();
        }

        try {
            $needsRehash = $hasher->needsRehash($dummyPasswordHash);
        } catch (Throwable) {
            throw self::invalidConfiguration();
        }

        if ($needsRehash !== false) {
            throw self::invalidConfiguration();
        }
    }

    private static function invalidConfiguration(): RuntimeException
    {
        return new RuntimeException('Mobile auth password timing configuration is invalid.');
    }
}
