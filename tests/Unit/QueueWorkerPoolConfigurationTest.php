<?php

namespace Tests\Unit;

use App\Support\Queue\QueueRuntimeConfiguration;
use App\Support\Queue\QueueWorkerPoolConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class QueueWorkerPoolConfigurationTest extends TestCase
{
    public function test_it_builds_a_safe_deduplicated_worker_plan(): void
    {
        $plan = QueueWorkerPoolConfiguration::plan(
            $this->configuration(),
            fn (string $key): mixed => $this->queues()[$key] ?? null,
        );

        $this->assertSame(
            ['callbacks-results', 'projections-publication', 'outbound-enrichment', 'ukm-scoring'],
            array_keys($plan),
        );
        $this->assertSame(['ai-callbacks', 'ai-results'], $plan['callbacks-results']['queues']);
        $this->assertSame(512, $plan['callbacks-results']['memory']);
        $this->assertSame(1024, $plan['ukm-scoring']['memory']);
        $this->assertSame(600, $plan['ukm-scoring']['timeout']);
        $this->assertSame(720, $plan['ukm-scoring']['stop_wait_seconds']);
    }

    public function test_it_rejects_unknown_fields_and_unsafe_runtime_bounds(): void
    {
        $unknown = $this->configuration();
        $unknown['defaults']['tries'] = 3;

        $this->expectException(RuntimeException::class);
        QueueWorkerPoolConfiguration::plan($unknown, fn (string $key): mixed => $this->queues()[$key] ?? null);
    }

    public function test_it_rejects_a_worker_timeout_that_diverges_from_the_job_invariant(): void
    {
        $configuration = $this->configuration();
        $configuration['defaults']['timeout'] = 599;

        $this->expectException(RuntimeException::class);
        QueueWorkerPoolConfiguration::plan(
            $configuration,
            fn (string $key): mixed => $this->queues()[$key] ?? null,
        );
    }

    public function test_it_rejects_an_unsafe_process_manager_stop_window(): void
    {
        $configuration = $this->configuration();
        $configuration['defaults']['stop_wait_seconds'] = 719;

        $this->expectException(RuntimeException::class);
        QueueWorkerPoolConfiguration::plan(
            $configuration,
            fn (string $key): mixed => $this->queues()[$key] ?? null,
        );
    }

    public function test_it_rejects_invalid_or_duplicate_resolved_queue_names(): void
    {
        $configuration = $this->configuration();
        $queues = $this->queues();
        $queues['mapilio.ai_result_persistence.queue'] = 'ai-callbacks';

        $this->expectException(RuntimeException::class);
        QueueWorkerPoolConfiguration::plan(
            $configuration,
            static fn (string $key): mixed => $queues[$key] ?? null,
        );
    }

    public function test_it_rejects_unapproved_queue_configuration_paths(): void
    {
        $configuration = $this->configuration();
        $configuration['pools']['callbacks-results']['queue_config_keys'][0] = 'database.connections.pgsql';

        $this->expectException(RuntimeException::class);
        QueueWorkerPoolConfiguration::plan(
            $configuration,
            fn (string $key): mixed => $this->queues()[$key] ?? null,
        );
    }

    /**
     * @return array{
     *     defaults: array<string, int>,
     *     pools: array<string, array{queue_config_keys: list<string>, memory?: int}>
     * }
     */
    private function configuration(): array
    {
        return [
            'defaults' => [
                'sleep' => 1,
                'timeout' => QueueRuntimeConfiguration::LONGEST_JOB_TIMEOUT_SECONDS,
                'max_time' => 3600,
                'max_jobs' => 1000,
                'memory' => 512,
                'stop_wait_seconds' => QueueRuntimeConfiguration::MINIMUM_GRACEFUL_STOP_SECONDS,
            ],
            'pools' => [
                'callbacks-results' => [
                    'queue_config_keys' => [
                        'mapilio.ai_callback.queue',
                        'mapilio.ai_result_persistence.queue',
                    ],
                ],
                'projections-publication' => [
                    'queue_config_keys' => [
                        'mapilio.ai_status_projection.queue',
                        'mapilio.geo_publication.queue',
                        'mapilio.geo_publication.preparation_queue',
                    ],
                ],
                'outbound-enrichment' => [
                    'queue_config_keys' => [
                        'mapilio.ai_prediction.queue',
                        'mapilio.address_enrichment.queue',
                    ],
                ],
                'ukm-scoring' => [
                    'queue_config_keys' => [
                        'mapilio.ukm_scoring.queue',
                    ],
                    'memory' => 1024,
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function queues(): array
    {
        return [
            'mapilio.ai_callback.queue' => 'ai-callbacks',
            'mapilio.ai_result_persistence.queue' => 'ai-results',
            'mapilio.ai_status_projection.queue' => 'ai-status-projections',
            'mapilio.geo_publication.queue' => 'geo-publications',
            'mapilio.geo_publication.preparation_queue' => 'geo-publication-preparation',
            'mapilio.ai_prediction.queue' => 'prediction',
            'mapilio.address_enrichment.queue' => 'find-address',
            'mapilio.ukm_scoring.queue' => 'ukm-scoring',
        ];
    }
}
