<?php

namespace Tests\Feature\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\AssertsQueryBudgets;
use Tests\TestCase;

class QueryBudgetAssertionTest extends TestCase
{
    use AssertsQueryBudgets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection('sqlite')->getDriverName());
        Schema::create('query_budget_allowed', fn ($table) => $table->id());
        Schema::create('query_budget_unrelated', fn ($table) => $table->id());
    }

    public function test_unrelated_table_queries_are_excluded_and_duplicate_connection_scopes_are_merged(): void
    {
        $this->assertQueryBudget(
            function (): void {
                DB::table('query_budget_allowed')->count();
                DB::table('query_budget_unrelated')->count();
            },
            1,
            [
                ['connection' => 'sqlite', 'tables' => ['query_budget_allowed']],
                ['connection' => DB::connection('sqlite'), 'tables' => ['query_budget_allowed']],
            ],
        );
    }

    public function test_exact_allowed_budget_passes(): void
    {
        $this->assertQueryBudget(
            fn (): mixed => DB::table('query_budget_allowed')->count(),
            1,
            [['connection' => 'sqlite', 'tables' => ['query_budget_allowed']]],
        );
    }

    public function test_no_dispatcher_connection_is_measured_and_restored_to_null(): void
    {
        $connection = clone DB::connection('sqlite');
        $connection->unsetEventDispatcher();

        $this->assertNull($connection->getEventDispatcher());

        $result = $this->assertQueryBudget(
            fn (): int => (int) $connection->table('query_budget_allowed')->count(),
            1,
            [['connection' => $connection, 'tables' => ['query_budget_allowed']]],
        );

        $this->assertSame(0, $result);
        $this->assertNull($connection->getEventDispatcher());
    }

    public function test_callback_result_and_existing_listener_are_preserved_without_leaking_dispatcher_state(): void
    {
        $connection = DB::connection('sqlite');
        $originalDispatcher = $connection->getEventDispatcher();
        $this->assertNotNull($originalDispatcher);
        $isolatedDispatcher = clone $originalDispatcher;
        $listenerCalls = 0;
        $isolatedDispatcher->listen(QueryExecuted::class, function () use (&$listenerCalls): void {
            $listenerCalls++;
        });
        $connection->setEventDispatcher($isolatedDispatcher);

        try {
            $result = $this->assertQueryBudget(
                function (): string {
                    DB::table('query_budget_allowed')->count();

                    return 'returned-value';
                },
                1,
                [['connection' => $connection, 'tables' => ['query_budget_allowed']]],
            );

            $this->assertSame('returned-value', $result);
            $this->assertSame(1, $listenerCalls);
            $this->assertSame($isolatedDispatcher, $connection->getEventDispatcher());
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertSame($originalDispatcher, $connection->getEventDispatcher());
    }

    public function test_fifth_matching_query_fails_and_original_dispatcher_is_restored(): void
    {
        $connection = DB::connection('sqlite');
        $originalDispatcher = $connection->getEventDispatcher();
        $failure = null;

        try {
            $this->assertQueryBudget(
                function (): void {
                    for ($query = 0; $query < 5; $query++) {
                        DB::table('query_budget_allowed')->count();
                    }
                },
                4,
                [['connection' => 'sqlite', 'tables' => ['query_budget_allowed']]],
            );
        } catch (ExpectationFailedException $exception) {
            $failure = $exception;
        } finally {
            $this->assertSame($originalDispatcher, $connection->getEventDispatcher());
        }

        $this->assertNotNull($failure);
        $this->assertInstanceOf(ExpectationFailedException::class, $failure);
        $this->assertStringContainsString('Expected exactly 4 scoped database queries; counted 5.', $failure->getMessage());
    }

    public function test_original_dispatcher_is_restored_when_callback_throws(): void
    {
        $connection = DB::connection('sqlite');
        $originalDispatcher = $connection->getEventDispatcher();
        $failure = null;

        try {
            $this->assertQueryBudget(
                fn (): mixed => $this->throwSyntheticCallbackFailure(),
                0,
                [['connection' => 'sqlite', 'tables' => ['query_budget_allowed']]],
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure);
        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertSame('synthetic callback failure', $failure->getMessage());
        $this->assertSame($originalDispatcher, $connection->getEventDispatcher());
    }

    private function throwSyntheticCallbackFailure(): mixed
    {
        throw new \RuntimeException('synthetic callback failure');
    }
}
