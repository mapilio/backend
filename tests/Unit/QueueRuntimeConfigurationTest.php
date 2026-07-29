<?php

namespace Tests\Unit;

use App\Support\Queue\QueueRuntimeConfiguration;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class QueueRuntimeConfigurationTest extends TestCase
{
    public function test_it_accepts_safe_async_and_in_process_connections(): void
    {
        QueueRuntimeConfiguration::assertSafe('database', [
            'database' => [
                'driver' => 'database',
                'retry_after' => QueueRuntimeConfiguration::MINIMUM_RETRY_WINDOW_SECONDS,
            ],
        ]);

        QueueRuntimeConfiguration::assertSafe('sync', [
            'sync' => ['driver' => 'sync'],
        ]);

        QueueRuntimeConfiguration::assertSafe('sqs', [
            'sqs' => [
                'driver' => 'sqs',
                'visibility_timeout' => QueueRuntimeConfiguration::MINIMUM_RETRY_WINDOW_SECONDS,
            ],
        ]);

        $this->addToAssertionCount(3);
    }

    public function test_it_validates_every_failover_child(): void
    {
        QueueRuntimeConfiguration::assertSafe('failover', [
            'failover' => [
                'driver' => 'failover',
                'connections' => ['database', 'deferred'],
            ],
            'database' => [
                'driver' => 'database',
                'retry_after' => QueueRuntimeConfiguration::MINIMUM_RETRY_WINDOW_SECONDS,
            ],
            'deferred' => ['driver' => 'deferred'],
        ]);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConfigurationProvider')]
    public function test_it_rejects_unprovable_retry_windows(mixed $default, mixed $connections): void
    {
        $this->expectException(RuntimeException::class);

        QueueRuntimeConfiguration::assertSafe($default, $connections);
    }

    public function test_retry_window_constant_covers_every_queued_job_timeout(): void
    {
        $jobFiles = glob(dirname(__DIR__, 2).'/app/Jobs/*.php');

        $this->assertIsArray($jobFiles);
        $this->assertNotEmpty($jobFiles);

        $timeouts = [];

        foreach ($jobFiles as $jobFile) {
            $class = 'App\\Jobs\\'.pathinfo($jobFile, PATHINFO_FILENAME);

            if (! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $timeout = $defaults['timeout'] ?? 60;

            $this->assertIsInt($timeout, "{$class} must declare an integer timeout.");
            $timeouts[$class] = $timeout;
        }

        $this->assertNotEmpty($timeouts);
        $this->assertSame(
            QueueRuntimeConfiguration::LONGEST_JOB_TIMEOUT_SECONDS,
            max($timeouts),
            'Update the queue retry-window invariant when the longest job timeout changes.',
        );
        $this->assertGreaterThan(
            max($timeouts),
            QueueRuntimeConfiguration::MINIMUM_RETRY_WINDOW_SECONDS,
        );
        $this->assertGreaterThan(
            max($timeouts),
            QueueRuntimeConfiguration::MINIMUM_GRACEFUL_STOP_SECONDS,
        );
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function unsafeConfigurationProvider(): array
    {
        return [
            'missing default' => [null, []],
            'missing connections' => ['database', null],
            'unknown connection' => ['database', []],
            'missing driver' => ['database', ['database' => []]],
            'missing retry window' => ['database', [
                'database' => ['driver' => 'database'],
            ]],
            'legacy 90 second retry' => ['database', [
                'database' => ['driver' => 'database', 'retry_after' => 90],
            ]],
            'string retry window' => ['database', [
                'database' => ['driver' => 'database', 'retry_after' => '660'],
            ]],
            'sqs without visibility evidence' => ['sqs', [
                'sqs' => ['driver' => 'sqs', 'visibility_timeout' => 0],
            ]],
            'empty failover' => ['failover', [
                'failover' => ['driver' => 'failover', 'connections' => []],
            ]],
            'invalid failover child' => ['failover', [
                'failover' => ['driver' => 'failover', 'connections' => [null]],
            ]],
            'unsafe failover child' => ['failover', [
                'failover' => ['driver' => 'failover', 'connections' => ['database']],
                'database' => ['driver' => 'database', 'retry_after' => 90],
            ]],
            'failover cycle' => ['failover', [
                'failover' => ['driver' => 'failover', 'connections' => ['failover']],
            ]],
        ];
    }
}
