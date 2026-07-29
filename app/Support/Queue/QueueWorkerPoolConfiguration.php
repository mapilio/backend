<?php

namespace App\Support\Queue;

use RuntimeException;

final class QueueWorkerPoolConfiguration
{
    private const DEFAULT_KEYS = [
        'sleep',
        'timeout',
        'max_time',
        'max_jobs',
        'memory',
        'stop_wait_seconds',
    ];

    private const POOL_KEYS = [
        'queue_config_keys',
        'memory',
    ];

    /**
     * @param  callable(string): mixed  $resolveQueue
     * @return array<string, array{
     *     queues: list<string>,
     *     sleep: int,
     *     timeout: int,
     *     max_time: int,
     *     max_jobs: int,
     *     memory: int,
     *     stop_wait_seconds: int
     * }>
     */
    public static function plan(mixed $configuration, callable $resolveQueue): array
    {
        if (! is_array($configuration)) {
            throw new RuntimeException('Queue worker pool configuration is missing.');
        }

        self::assertExactKeys($configuration, ['defaults', 'pools'], 'Queue worker pool configuration');

        $defaults = $configuration['defaults'] ?? null;
        $pools = $configuration['pools'] ?? null;

        if (! is_array($defaults)) {
            throw new RuntimeException('Queue worker pool defaults are missing.');
        }

        self::assertExactKeys($defaults, self::DEFAULT_KEYS, 'Queue worker pool defaults');
        self::assertRuntimeValues($defaults);

        if (! is_array($pools) || $pools === []) {
            throw new RuntimeException('Queue worker pools are missing.');
        }

        $plan = [];
        $assignedQueues = [];

        foreach ($pools as $poolName => $pool) {
            if (! is_string($poolName) || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $poolName) !== 1) {
                throw new RuntimeException('Queue worker pool name is invalid.');
            }

            if (! is_array($pool)) {
                throw new RuntimeException("Queue worker pool [{$poolName}] is invalid.");
            }

            self::assertAllowedKeys($pool, self::POOL_KEYS, "Queue worker pool [{$poolName}]");

            $queueConfigKeys = $pool['queue_config_keys'] ?? null;

            if (! is_array($queueConfigKeys) || $queueConfigKeys === []) {
                throw new RuntimeException("Queue worker pool [{$poolName}] has no queue configuration keys.");
            }

            $queues = [];

            foreach ($queueConfigKeys as $queueConfigKey) {
                if (
                    ! is_string($queueConfigKey)
                    || preg_match('/\Amapilio\.[a-z0-9_]+\.(?:queue|preparation_queue)\z/D', $queueConfigKey) !== 1
                ) {
                    throw new RuntimeException("Queue worker pool [{$poolName}] contains an invalid queue configuration key.");
                }

                $queue = $resolveQueue($queueConfigKey);

                if (
                    ! is_string($queue)
                    || $queue !== trim($queue)
                    || preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $queue) !== 1
                ) {
                    throw new RuntimeException("Queue worker pool [{$poolName}] resolves an invalid queue name.");
                }

                if (isset($assignedQueues[$queue])) {
                    throw new RuntimeException('A queue is assigned to more than one worker pool.');
                }

                $assignedQueues[$queue] = true;
                $queues[] = $queue;
            }

            $values = $defaults;

            if (array_key_exists('memory', $pool)) {
                $values['memory'] = $pool['memory'];
            }

            self::assertRuntimeValues($values, $poolName);

            $plan[$poolName] = [
                'queues' => $queues,
                'sleep' => $values['sleep'],
                'timeout' => $values['timeout'],
                'max_time' => $values['max_time'],
                'max_jobs' => $values['max_jobs'],
                'memory' => $values['memory'],
                'stop_wait_seconds' => $values['stop_wait_seconds'],
            ];
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function assertRuntimeValues(array $values, ?string $poolName = null): void
    {
        $context = $poolName === null ? 'Queue worker pool defaults' : "Queue worker pool [{$poolName}]";

        foreach (self::DEFAULT_KEYS as $key) {
            if (! array_key_exists($key, $values) || ! is_int($values[$key])) {
                throw new RuntimeException("{$context} must provide integer [{$key}].");
            }
        }

        if ($values['sleep'] < 0 || $values['sleep'] > 10) {
            throw new RuntimeException("{$context} sleep must be between 0 and 10 seconds.");
        }

        if ($values['timeout'] !== QueueRuntimeConfiguration::LONGEST_JOB_TIMEOUT_SECONDS) {
            throw new RuntimeException(
                "{$context} timeout must equal ".QueueRuntimeConfiguration::LONGEST_JOB_TIMEOUT_SECONDS.' seconds.',
            );
        }

        if ($values['max_time'] <= $values['timeout'] || $values['max_time'] > 86400) {
            throw new RuntimeException("{$context} max_time must exceed timeout and be at most 86400 seconds.");
        }

        if ($values['max_jobs'] < 1 || $values['max_jobs'] > 100000) {
            throw new RuntimeException("{$context} max_jobs must be between 1 and 100000.");
        }

        if ($values['memory'] < 128 || $values['memory'] > 4096) {
            throw new RuntimeException("{$context} memory must be between 128 and 4096 MiB.");
        }

        if (
            $values['stop_wait_seconds'] < QueueRuntimeConfiguration::MINIMUM_GRACEFUL_STOP_SECONDS
            || $values['stop_wait_seconds'] > 86400
        ) {
            throw new RuntimeException(
                "{$context} stop_wait_seconds must be between "
                .QueueRuntimeConfiguration::MINIMUM_GRACEFUL_STOP_SECONDS
                .' and 86400 seconds.',
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(array $values, array $expected, string $context): void
    {
        self::assertAllowedKeys($values, $expected, $context);

        foreach ($expected as $key) {
            if (! array_key_exists($key, $values)) {
                throw new RuntimeException("{$context} is missing [{$key}].");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $allowed
     */
    private static function assertAllowedKeys(array $values, array $allowed, string $context): void
    {
        foreach (array_keys($values) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new RuntimeException("{$context} contains an unsupported field.");
            }
        }
    }
}
