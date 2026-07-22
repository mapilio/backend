<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

trait AssertsQueryBudgets
{
    /**
     * Assert the number of queries touching explicitly scoped tables.
     *
     * Each scope is an array with a connection name (or Connection instance)
     * and a list of exact table identifiers:
     * ['connection' => 'sqlite', 'tables' => ['features']]
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  array<int, array{connection: Connection|string, tables: array<int, string>}>  $scopes
     * @return TReturn
     */
    protected function assertQueryBudget(callable $callback, int $expected, array $scopes): mixed
    {
        $connections = [];

        foreach ($scopes as $scope) {
            $connection = $scope['connection'] instanceof Connection
                ? $scope['connection']
                : DB::connection($scope['connection']);
            $connectionId = spl_object_id($connection);

            if (! isset($connections[$connectionId])) {
                $connections[$connectionId] = [
                    'connection' => $connection,
                    'tables' => [],
                ];
            }

            $connections[$connectionId]['tables'] = array_values(array_unique(array_merge(
                $connections[$connectionId]['tables'],
                array_map([$this, 'normalizeTableIdentifier'], $scope['tables']),
            )));
        }

        $counts = array_fill_keys(array_keys($connections), 0);
        $originalDispatchers = [];

        try {
            foreach ($connections as $connectionId => $configuration) {
                $connection = $configuration['connection'];
                $originalDispatchers[$connectionId] = $connection->getEventDispatcher();
                $dispatcher = $originalDispatchers[$connectionId] === null
                    ? new Dispatcher(app())
                    : clone $originalDispatchers[$connectionId];
                $tables = $configuration['tables'];

                $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$counts, $connectionId, $tables): void {
                    if (array_intersect($tables, $this->queryTableIdentifiers($query->sql)) !== []) {
                        $counts[$connectionId]++;
                    }
                });

                $connection->setEventDispatcher($dispatcher);
            }

            $result = $callback();

            $this->assertSame(
                $expected,
                array_sum($counts),
                "Expected exactly {$expected} scoped database queries; counted ".array_sum($counts).'.',
            );

            return $result;
        } finally {
            foreach ($originalDispatchers as $connectionId => $dispatcher) {
                if ($dispatcher === null) {
                    $connections[$connectionId]['connection']->unsetEventDispatcher();
                } else {
                    $connections[$connectionId]['connection']->setEventDispatcher($dispatcher);
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function queryTableIdentifiers(string $sql): array
    {
        $identifier = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_$-]*)';
        preg_match_all(
            '/\b(?:from|join|update|into)\s+('
                .$identifier.'(?:\s*\.\s*'.$identifier.')*)/i',
            $sql,
            $matches,
        );

        return array_map([$this, 'normalizeTableIdentifier'], $matches[1]);
    }

    private function normalizeTableIdentifier(string $identifier): string
    {
        return strtolower((string) preg_replace('/["`\[\]\s]/', '', $identifier));
    }
}
