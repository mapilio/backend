<?php

namespace Tests\Unit\Database;

use App\Domain\DataMigration\PostgresqlEndpointNormalizer;
use PHPUnit\Framework\TestCase;

final class PostgresqlEndpointNormalizerTest extends TestCase
{
    private PostgresqlEndpointNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PostgresqlEndpointNormalizer;
    }

    public function test_valid_structured_and_url_configurations_normalize_to_identity(): void
    {
        $this->assertSame('pgsql', $this->normalizer->effectiveDriver(['driver' => 'sqlite', 'url' => 'postgresql://url.example/database']));
        $this->assertSame('host.example:5432:database', $this->normalizer->normalize(['driver' => 'pgsql', 'host' => 'HOST.EXAMPLE', 'port' => '5432', 'database' => 'database']));
        $this->assertSame('host.example:5432:database', $this->normalizer->normalize(['driver' => 'sqlite', 'url' => 'postgresql://url.example/ignored?host=HOST.EXAMPLE&port=5432&database=database']));
    }

    public function test_trailing_dns_dot_is_equivalent(): void
    {
        $this->assertSame(
            $this->normalizer->normalize(['driver' => 'pgsql', 'host' => 'host.example.', 'database' => 'database']),
            $this->normalizer->normalize(['driver' => 'pgsql', 'host' => 'host.example', 'database' => 'database']),
        );
    }

    public function test_malformed_control_slash_and_backslash_values_fail_closed(): void
    {
        foreach ([
            ['host' => 'host example'], ['host' => "host\nexample"], ['host' => 'host/example'], ['host' => 'host\\example'],
            ['database' => 'db/name'], ['database' => 'db\\name'], ['database' => "db\tname"], ['host' => ''],
        ] as $override) {
            $config = ['driver' => 'pgsql', 'host' => 'host.example', 'port' => 5432, 'database' => 'database'];
            $this->assertNull($this->normalizer->normalize(array_merge($config, $override)));
        }
    }

    public function test_invalid_ports_and_non_postgresql_effective_drivers_fail_closed(): void
    {
        foreach ([0, 65536, '5432x', ''] as $port) {
            $this->assertNull($this->normalizer->normalize(['driver' => 'pgsql', 'host' => 'host.example', 'port' => $port, 'database' => 'database']));
        }
        $this->assertNull($this->normalizer->normalize(['driver' => 'mysql', 'host' => 'host.example', 'port' => 3306, 'database' => 'database']));
    }
}
