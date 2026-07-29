<?php

namespace Tests\Feature;

use App\Support\Queue\QueueRuntimeConfiguration;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class QueueWorkerPoolCommandTest extends TestCase
{
    public function test_dry_run_prints_the_sanitized_effective_pool_plan(): void
    {
        $this->artisan('mapilio:queue-work', [
            'pool' => 'callbacks-results',
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutput('QUEUE_WORKER_POOL_READY')
            ->expectsOutput('pool=callbacks-results')
            ->expectsOutput('connection='.(string) config('queue.default'))
            ->expectsOutput('queues=ai-callbacks,ai-results')
            ->expectsOutput('sleep=1')
            ->expectsOutput('timeout=600')
            ->expectsOutput('max_time=3600')
            ->expectsOutput('max_jobs=1000')
            ->expectsOutput('memory=512')
            ->expectsOutput('stop_wait_seconds=720');
    }

    public function test_dry_run_uses_approved_queue_aliases_from_mapilio_configuration(): void
    {
        Config::set('mapilio.ai_callback.queue', 'staging-ai-callbacks');
        Config::set('mapilio.ai_result_persistence.queue', 'staging-ai-results');

        $this->artisan('mapilio:queue-work', [
            'pool' => 'callbacks-results',
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutput('queues=staging-ai-callbacks,staging-ai-results');
    }

    public function test_unknown_pool_fails_without_printing_the_available_plan(): void
    {
        $this->artisan('mapilio:queue-work', [
            'pool' => 'missing-pool',
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutput('QUEUE_WORKER_POOL_UNKNOWN')
            ->doesntExpectOutput('ai-callbacks');
    }

    public function test_invalid_runtime_configuration_fails_closed(): void
    {
        Config::set('queue-workers.defaults.stop_wait_seconds', 719);

        $this->artisan('mapilio:queue-work', [
            'pool' => 'callbacks-results',
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutput('QUEUE_WORKER_PLAN_INVALID')
            ->doesntExpectOutput('ai-callbacks');
    }

    public function test_supervisor_template_preserves_the_validated_runtime_contract(): void
    {
        $template = file_get_contents(
            base_path('deployment/supervisor/mapilio-queue-workers.conf.example'),
        );

        $this->assertIsString($template);

        foreach (array_keys(config('queue-workers.pools')) as $pool) {
            $this->assertStringContainsString(
                "artisan mapilio:queue-work {$pool} --no-interaction",
                $template,
            );
        }

        $poolCount = count(config('queue-workers.pools'));

        $this->assertSame($poolCount, substr_count($template, 'numprocs=1'));
        $this->assertSame($poolCount, substr_count($template, 'autorestart=true'));
        $this->assertSame($poolCount, substr_count($template, 'stopasgroup=true'));
        $this->assertSame($poolCount, substr_count($template, 'killasgroup=true'));
        $this->assertSame(
            $poolCount,
            substr_count(
                $template,
                'stopwaitsecs='.QueueRuntimeConfiguration::MINIMUM_GRACEFUL_STOP_SECONDS,
            ),
        );
        $this->assertStringContainsString('directory=__MAPILIO_RELEASE_DIR__', $template);
        $this->assertStringContainsString('user=__MAPILIO_APP_USER__', $template);
        $this->assertStringNotContainsString('APP_KEY=', $template);
        $this->assertStringNotContainsString('PASSWORD=', $template);
    }
}
