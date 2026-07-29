<?php

namespace App\Console\Commands;

use App\Support\Queue\QueueWorkerPoolConfiguration;
use Illuminate\Console\Command;
use RuntimeException;

class WorkQueuePool extends Command
{
    protected $signature = 'mapilio:queue-work
        {pool : Configured queue worker pool name}
        {--dry-run : Validate and print the sanitized worker plan without starting a worker}';

    protected $description = 'Run one validated Mapilio queue worker pool';

    public function handle(): int
    {
        try {
            $plan = QueueWorkerPoolConfiguration::plan(
                config('queue-workers'),
                static fn (string $key): mixed => config($key),
            );
        } catch (RuntimeException $exception) {
            $this->error('QUEUE_WORKER_PLAN_INVALID');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $poolName = $this->argument('pool');

        if (! isset($plan[$poolName])) {
            $this->error('QUEUE_WORKER_POOL_UNKNOWN');

            return self::FAILURE;
        }

        $pool = $plan[$poolName];
        $connection = config('queue.default');

        if (! is_string($connection) || trim($connection) === '') {
            $this->error('QUEUE_WORKER_CONNECTION_INVALID');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('QUEUE_WORKER_POOL_READY');
            $this->line("pool={$poolName}");
            $this->line("connection={$connection}");
            $this->line('queues='.implode(',', $pool['queues']));
            $this->line("sleep={$pool['sleep']}");
            $this->line("timeout={$pool['timeout']}");
            $this->line("max_time={$pool['max_time']}");
            $this->line("max_jobs={$pool['max_jobs']}");
            $this->line("memory={$pool['memory']}");
            $this->line("stop_wait_seconds={$pool['stop_wait_seconds']}");

            return self::SUCCESS;
        }

        return $this->call('queue:work', [
            'connection' => $connection,
            '--queue' => implode(',', $pool['queues']),
            '--sleep' => $pool['sleep'],
            '--timeout' => $pool['timeout'],
            '--max-time' => $pool['max_time'],
            '--max-jobs' => $pool['max_jobs'],
            '--memory' => $pool['memory'],
        ]);
    }
}
