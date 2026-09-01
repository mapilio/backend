<?php

namespace Tests\Unit;

use App\Support\Database\LegacySchemaCapabilities;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class LegacySchemaCapabilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('legacy_capability_probe', function ($table): void {
            $table->id();
            $table->string('MixedCaseColumn');
            $table->string('another_column')->nullable();
        });
    }

    public function test_it_snapshots_a_table_and_reuses_the_complete_column_set(): void
    {
        $capabilities = app(LegacySchemaCapabilities::class);
        $metadataReads = 0;
        DB::connection()->listen(static function (QueryExecuted $query) use (&$metadataReads): void {
            $metadataReads++;
        });

        $this->assertTrue($capabilities->hasTable('legacy_capability_probe'));
        $this->assertTrue($capabilities->hasColumn('legacy_capability_probe', 'MIXEDCASECOLUMN'));
        $this->assertTrue($capabilities->hasColumn('legacy_capability_probe', 'another_column'));
        $this->assertFalse($capabilities->hasColumn('legacy_capability_probe', 'missing_column'));
        $this->assertSame([
            'MIXEDCASECOLUMN' => 'keep',
        ], $capabilities->filterExistingColumns('legacy_capability_probe', [
            'MIXEDCASECOLUMN' => 'keep',
            'missing_column' => 'drop',
        ]));

        $readsAfterFirstSnapshot = $metadataReads;
        $this->assertGreaterThan(0, $readsAfterFirstSnapshot);

        $capabilities->hasTable('legacy_capability_probe');
        $capabilities->hasColumn('legacy_capability_probe', 'mixedcasecolumn');
        $capabilities->hasColumn('legacy_capability_probe', 'another_column');
        $capabilities->filterExistingColumns('legacy_capability_probe', [
            'another_column' => 'keep',
            'missing_column' => 'drop',
        ]);

        $this->assertSame($readsAfterFirstSnapshot, $metadataReads);
    }

    public function test_absent_tables_and_columns_are_cached_within_the_scope(): void
    {
        $capabilities = app(LegacySchemaCapabilities::class);
        $metadataReads = 0;
        DB::connection()->listen(static function (QueryExecuted $query) use (&$metadataReads): void {
            $metadataReads++;
        });

        $this->assertFalse($capabilities->hasTable('missing_legacy_table'));
        $readsAfterAbsentTable = $metadataReads;
        $this->assertGreaterThan(0, $readsAfterAbsentTable);
        $this->assertFalse($capabilities->hasTable('missing_legacy_table'));
        $this->assertSame([], $capabilities->filterExistingColumns('missing_legacy_table', [
            'missing_column' => 'value',
        ]));
        $this->assertSame($readsAfterAbsentTable, $metadataReads);

        $this->assertFalse($capabilities->hasColumn('legacy_capability_probe', 'missing_column'));
        $readsAfterAbsentColumn = $metadataReads;
        $this->assertFalse($capabilities->hasColumn('legacy_capability_probe', 'missing_column'));
        $this->assertSame($readsAfterAbsentColumn, $metadataReads);
    }

    public function test_snapshots_are_isolated_by_connection_name(): void
    {
        $connectionName = 'legacy_schema_secondary';
        $previousConfiguration = config("database.connections.{$connectionName}");
        config([
            "database.connections.{$connectionName}" => array_merge(
                (array) config('database.connections.sqlite'),
                ['database' => ':memory:'],
            ),
        ]);
        DB::purge($connectionName);

        try {
            DB::connection($connectionName)->getSchemaBuilder()->create('legacy_capability_probe', function ($table): void {
                $table->id();
                $table->string('secondary_only');
            });

            $capabilities = app(LegacySchemaCapabilities::class);

            $this->assertTrue($capabilities->hasTable('legacy_capability_probe'));
            $this->assertTrue($capabilities->hasColumn('legacy_capability_probe', 'MixedCaseColumn'));
            $this->assertFalse($capabilities->hasColumn('legacy_capability_probe', 'secondary_only'));
            $this->assertTrue($capabilities->hasTable('legacy_capability_probe', $connectionName));
            $this->assertTrue($capabilities->hasColumn('legacy_capability_probe', 'secondary_only', $connectionName));
            $this->assertFalse($capabilities->hasColumn('legacy_capability_probe', 'MixedCaseColumn', $connectionName));
        } finally {
            DB::purge($connectionName);
            config(["database.connections.{$connectionName}" => $previousConfiguration]);
        }
    }

    public function test_metadata_exception_during_column_listing_does_not_publish_a_partial_snapshot(): void
    {
        $capabilities = app(LegacySchemaCapabilities::class);
        $metadataQueries = 0;
        DB::connection()->listen(static function (QueryExecuted $query) use (&$metadataQueries): void {
            $metadataQueries++;

            if ($metadataQueries === 2) {
                throw new RuntimeException('Synthetic metadata failure.');
            }
        });

        try {
            $capabilities->hasColumn('legacy_capability_probe', 'MixedCaseColumn');
            $this->fail('The first metadata read should propagate its exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic metadata failure.', $exception->getMessage());
        }

        $this->assertSame(2, $metadataQueries);
        $this->assertTrue($capabilities->hasColumn('legacy_capability_probe', 'MixedCaseColumn'));
    }

    public function test_forgetting_scoped_instances_exposes_schema_changes_to_a_fresh_service(): void
    {
        $oldCapabilities = app(LegacySchemaCapabilities::class);

        $this->assertFalse($oldCapabilities->hasColumn('legacy_capability_probe', 'added_column'));
        Schema::table('legacy_capability_probe', function ($table): void {
            $table->string('added_column')->nullable();
        });

        $this->assertFalse($oldCapabilities->hasColumn('legacy_capability_probe', 'added_column'));

        app()->forgetScopedInstances();
        $freshCapabilities = app(LegacySchemaCapabilities::class);

        $this->assertNotSame($oldCapabilities, $freshCapabilities);
        $this->assertTrue($freshCapabilities->hasColumn('legacy_capability_probe', 'added_column'));
    }
}
