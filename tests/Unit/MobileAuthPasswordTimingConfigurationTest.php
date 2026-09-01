<?php

namespace Tests\Unit;

use App\Support\Security\MobileAuthPasswordTimingConfiguration;
use Illuminate\Contracts\Hashing\Hasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MobileAuthPasswordTimingConfigurationTest extends TestCase
{
    public function test_it_accepts_a_hash_the_active_hasher_does_not_need_to_rehash(): void
    {
        $hasher = $this->createMock(Hasher::class);
        $hasher->expects($this->once())
            ->method('needsRehash')
            ->with('configured-dummy-hash')
            ->willReturn(false);

        MobileAuthPasswordTimingConfiguration::assertSafe('configured-dummy-hash', $hasher);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function test_it_rejects_missing_or_malformed_configuration(mixed $configuration): void
    {
        $hasher = $this->createMock(Hasher::class);
        $hasher->expects($this->never())->method('needsRehash');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mobile auth password timing configuration is invalid.');

        MobileAuthPasswordTimingConfiguration::assertSafe($configuration, $hasher);
    }

    public function test_it_rejects_a_hash_that_needs_rehash_without_exposing_it(): void
    {
        $staleHash = 'stale-dummy-hash';
        $hasher = $this->createMock(Hasher::class);
        $hasher->expects($this->once())
            ->method('needsRehash')
            ->with($staleHash)
            ->willReturn(true);

        try {
            MobileAuthPasswordTimingConfiguration::assertSafe($staleHash, $hasher);
            $this->fail('Expected invalid mobile auth password timing configuration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile auth password timing configuration is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString($staleHash, $exception->getMessage());
        }
    }

    public function test_it_rejects_a_hash_the_active_hasher_cannot_parse_without_exposing_it(): void
    {
        $malformedHash = 'malformed-dummy-hash';
        $hasher = $this->createMock(Hasher::class);
        $hasher->expects($this->once())
            ->method('needsRehash')
            ->with($malformedHash)
            ->willThrowException(new RuntimeException('hasher rejected value'));

        try {
            MobileAuthPasswordTimingConfiguration::assertSafe($malformedHash, $hasher);
            $this->fail('Expected invalid mobile auth password timing configuration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile auth password timing configuration is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString($malformedHash, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidConfigurationProvider(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'whitespace only' => ['   '],
            'non-string' => [42],
        ];
    }
}
