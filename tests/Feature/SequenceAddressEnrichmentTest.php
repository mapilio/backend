<?php

namespace Tests\Feature;

use App\Domain\ImagerySequences\Actions\ResolveSequenceAddress;
use App\Domain\ImagerySequences\Actions\SequenceAddressException;
use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SequenceAddressEnrichmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.address_enrichment.enabled', true);
        Config::set('mapilio.address_enrichment.endpoint', 'https://address.example.test/reverse');
        Config::set('mapilio.address_enrichment.user_agent', 'MapilioAddressTest/1.0');
        Config::set('mapilio.address_enrichment.max_point_attempts', 3);

        $this->createTables();
        $this->seedSequence();
    }

    public function test_photon_result_fills_missing_sequence_and_imagery_addresses(): void
    {
        Http::fake([
            'https://address.example.test/reverse*' => Http::response([
                'features' => [
                    [
                        'type' => 'Feature',
                        'properties' => [
                            'street' => "  Moda Caddesi\n",
                            'city' => 'Istanbul',
                        ],
                    ],
                ],
            ]),
        ]);
        $metadataQueries = [];
        LegacyDatabase::connection()->listen(static function (QueryExecuted $query) use (&$metadataQueries): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'pragma_table_')) {
                $metadataQueries[] = 'column-listing';
            } elseif (str_contains($sql, 'sqlite_master')) {
                $metadataQueries[] = 'sqlite-master';
            }
        });

        $result = app(ResolveSequenceAddress::class)->resolve('sequence-address-1');

        $this->assertSame([
            'resolved' => true,
            'status' => 'found',
            'address' => 'Moda Caddesi',
            'attempts' => 1,
        ], $result);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_PATH) === '/reverse'
                && (float) ($query['lat'] ?? 0) === 40.991
                && (float) ($query['lon'] ?? 0) === 29.025
                && $request->hasHeader('User-Agent', 'MapilioAddressTest/1.0');
        });
        Http::assertSentCount(1);
        $this->assertSame(['sqlite-master', 'column-listing', 'sqlite-master'], $metadataQueries);
        $this->assertCount(3, $metadataQueries);

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-address-1',
            'start_address' => 'Moda Caddesi',
            'address_status' => 1,
            'address_status_message' => null,
        ]);
        $this->assertSame(2, Schema::getConnection()->table('default_mapilio_imagery')
            ->where('capture_address', 'Moda Caddesi')
            ->count());
        $this->assertDatabaseHas('default_mapilio_imagery', [
            'id' => 3,
            'anomaly' => true,
            'capture_address' => 'Keep Existing',
        ]);
    }

    public function test_existing_client_address_wins_without_external_request(): void
    {
        Schema::getConnection()->table('default_mapilio_imagery')
            ->where('id', 1)
            ->update(['capture_address' => '  Client Road  ']);
        Config::set('mapilio.address_enrichment.endpoint', null);
        Http::fake();

        $result = app(ResolveSequenceAddress::class)->resolve('sequence-address-1');

        $this->assertSame('existing', $result['status']);
        $this->assertSame('Client Road', $result['address']);
        $this->assertSame(0, $result['attempts']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-address-1',
            'start_address' => 'Client Road',
            'address_status' => 1,
        ]);
        $this->assertDatabaseHas('default_mapilio_imagery', [
            'id' => 1,
            'capture_address' => '  Client Road  ',
        ]);
        $this->assertDatabaseHas('default_mapilio_imagery', [
            'id' => 2,
            'capture_address' => 'Client Road',
        ]);
    }

    public function test_empty_results_stop_at_configured_point_limit_and_mark_not_found(): void
    {
        Config::set('mapilio.address_enrichment.max_point_attempts', 2);
        Http::fakeSequence()
            ->push(['features' => []])
            ->push(['features' => []]);

        $result = app(ResolveSequenceAddress::class)->resolve('sequence-address-1');

        $this->assertSame([
            'resolved' => false,
            'status' => 'not_found',
            'address' => null,
            'attempts' => 2,
        ], $result);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-address-1',
            'start_address' => null,
            'address_status' => 2,
            'address_status_message' => null,
        ]);
    }

    public function test_provider_failure_records_safe_retryable_error(): void
    {
        Http::fake([
            'https://address.example.test/reverse*' => Http::response([
                'private' => 'sensitive provider response',
            ], 503),
        ]);

        try {
            app(ResolveSequenceAddress::class)->resolve('sequence-address-1');
            $this->fail('Address lookup should have failed.');
        } catch (SequenceAddressException $exception) {
            $this->assertSame('Address provider request failed with HTTP 503.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive provider response', $exception->getMessage());
        }

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-address-1',
            'address_status' => 3,
            'address_status_message' => 'Address provider request failed with HTTP 503.',
        ]);
    }

    public function test_invalid_success_payload_is_not_treated_as_not_found(): void
    {
        Http::fake([
            'https://address.example.test/reverse*' => Http::response(['unexpected' => true]),
        ]);

        $this->expectException(SequenceAddressException::class);
        $this->expectExceptionMessage('Address provider returned an invalid response.');

        app(ResolveSequenceAddress::class)->resolve('sequence-address-1');
    }

    public function test_missing_provider_endpoint_records_configuration_error_without_request(): void
    {
        Config::set('mapilio.address_enrichment.endpoint', null);
        Http::fake();

        try {
            app(ResolveSequenceAddress::class)->resolve('sequence-address-1');
            $this->fail('Address lookup should require a provider endpoint.');
        } catch (SequenceAddressException $exception) {
            $this->assertSame('Address provider endpoint must be a valid HTTP URL.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-address-1',
            'address_status' => 3,
            'address_status_message' => 'Address provider endpoint must be a valid HTTP URL.',
        ]);
    }

    public function test_disabled_enrichment_has_no_side_effects(): void
    {
        Config::set('mapilio.address_enrichment.enabled', false);
        Http::fake();

        $result = app(ResolveSequenceAddress::class)->resolve('sequence-address-1');

        $this->assertSame('disabled', $result['status']);
        $this->assertSame(0, $result['attempts']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-address-1',
            'start_address' => null,
            'address_status' => null,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->string('sequence_uuid');
            $table->boolean('anomaly')->default(false);
            $table->string('start_address')->nullable();
            $table->integer('address_status')->nullable();
            $table->text('address_status_message')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->string('sequence_uuid');
            $table->boolean('anomaly')->default(false);
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->string('capture_address')->nullable();
        });
    }

    private function seedSequence(): void
    {
        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
            'sequence_uuid' => 'sequence-address-1',
            'anomaly' => false,
            'start_address' => null,
            'address_status' => null,
            'address_status_message' => null,
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            [
                'id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
                'sequence_uuid' => 'sequence-address-1',
                'anomaly' => false,
                'latitude' => 40.991,
                'longitude' => 29.025,
                'capture_address' => null,
            ],
            [
                'id' => 2,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
                'sequence_uuid' => 'sequence-address-1',
                'anomaly' => false,
                'latitude' => 40.992,
                'longitude' => 29.026,
                'capture_address' => null,
            ],
            [
                'id' => 3,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
                'sequence_uuid' => 'sequence-address-1',
                'anomaly' => true,
                'latitude' => 40.993,
                'longitude' => 29.027,
                'capture_address' => 'Keep Existing',
            ],
        ]);
    }
}
