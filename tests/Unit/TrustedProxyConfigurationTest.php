<?php

namespace Tests\Unit;

use App\Support\Network\TrustedProxyConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_it_parses_deduplicates_and_preserves_explicit_ip_ranges(): void
    {
        $this->assertSame(
            ['192.0.2.1', '198.51.100.0/24', '2001:db8::/32'],
            TrustedProxyConfiguration::parse(' 192.0.2.1,198.51.100.0/24,192.0.2.1,2001:db8::/32 '),
        );

        $this->assertSame([], TrustedProxyConfiguration::parse(null));
        $this->assertSame([], TrustedProxyConfiguration::parse('  '));
    }

    #[DataProvider('invalidProxyProvider')]
    public function test_it_rejects_unsafe_or_invalid_proxy_entries(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        TrustedProxyConfiguration::parse($value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProxyProvider(): array
    {
        return [
            'wildcard' => ['*'],
            'double wildcard' => ['**'],
            'calling address shortcut' => ['REMOTE_ADDR'],
            'hostname' => ['proxy.example.test'],
            'invalid IPv4' => ['999.10.10.10'],
            'invalid IPv4 prefix' => ['192.0.2.0/33'],
            'invalid IPv6 prefix' => ['2001:db8::/129'],
            'negative prefix' => ['192.0.2.0/-1'],
            'multiple separators' => ['192.0.2.0/24/1'],
            'empty list entry' => ['192.0.2.1,,192.0.2.2'],
        ];
    }
}
