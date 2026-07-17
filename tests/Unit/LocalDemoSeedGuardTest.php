<?php

namespace Tests\Unit;

use App\Support\Database\LocalDemoSeedGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalDemoSeedGuardTest extends TestCase
{
    public function test_it_allows_explicit_local_sqlite_seeding(): void
    {
        LocalDemoSeedGuard::assertAllowed('local', true, 'sqlite');
        LocalDemoSeedGuard::assertAllowed('testing', true, 'sqlite');

        $this->addToAssertionCount(2);
    }

    #[DataProvider('blockedConfigurationProvider')]
    public function test_it_fails_closed_for_unsafe_or_malformed_configuration(
        string $environment,
        mixed $enabled,
        string $driver,
    ): void {
        $this->expectException(RuntimeException::class);

        LocalDemoSeedGuard::assertAllowed($environment, $enabled, $driver);
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function blockedConfigurationProvider(): array
    {
        return [
            'disabled' => ['local', false, 'sqlite'],
            'string boolean' => ['local', 'true', 'sqlite'],
            'missing boolean' => ['local', null, 'sqlite'],
            'production' => ['production', true, 'sqlite'],
            'staging' => ['staging', true, 'sqlite'],
            'postgresql' => ['local', true, 'pgsql'],
            'mysql' => ['testing', true, 'mysql'],
        ];
    }
}
