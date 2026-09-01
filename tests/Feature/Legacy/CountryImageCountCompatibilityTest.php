<?php

namespace Tests\Feature\Legacy;

use App\Support\Cache\PublicAggregateCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @phpstan-type CountryImageCountRow array{name: string, lon: string, lat: string, iso3: string, image_count: int} */
class CountryImageCountCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.rate_limiting.enabled', false);
        Config::set('mapilio.rate_limiting.enforce', false);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));
        Cache::forget(PublicAggregateCache::COUNTRY_IMAGE_COUNT_KEY);

        Schema::create('country_image_count', function ($table): void {
            $table->string('name')->nullable();
            $table->string('lon')->nullable();
            $table->string('lat')->nullable();
            $table->string('iso3', 3)->nullable();
            $table->integer('image_count')->nullable();
        });

        $this->insertCountry('Algeria', '2.63', '28.16', 'DZA', 500);
        $this->insertCountry('Armenia', '44.56', '40.53', 'ARM', 1720);
    }

    public function test_legacy_country_image_count_path_preserves_response_shape(): void
    {
        $response = $this->getJson('/api/country-image-count')->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(
            $this->rowsByIso3($this->expectedPopulatedRows()),
            $this->rowsByIso3($response->json('data')),
        );
    }

    public function test_versioned_country_image_count_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/country-image-count')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/country-image-count')
            ->assertOk()
            ->json();

        $this->assertSame(['data'], array_keys($legacy));
        $this->assertSame(['data'], array_keys($versioned));
        $this->assertSame(
            $this->rowsByIso3($legacy['data']),
            $this->rowsByIso3($versioned['data']),
        );
    }

    public function test_country_image_count_empty_result_is_exactly_an_empty_data_array(): void
    {
        Schema::getConnection()->table('country_image_count')->delete();
        Cache::forget(PublicAggregateCache::COUNTRY_IMAGE_COUNT_KEY);

        $this->getJson('/api/v1/imagery/country-image-count')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_country_image_count_casts_null_columns_to_empty_strings_and_zero(): void
    {
        $this->insertNullCountry();
        Cache::forget(PublicAggregateCache::COUNTRY_IMAGE_COUNT_KEY);

        $response = $this->getJson('/api/v1/imagery/country-image-count')->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(
            $this->rowsByIso3([
                [
                    'name' => 'Algeria',
                    'lon' => '2.63',
                    'lat' => '28.16',
                    'iso3' => 'DZA',
                    'image_count' => 500,
                ],
                [
                    'name' => 'Armenia',
                    'lon' => '44.56',
                    'lat' => '40.53',
                    'iso3' => 'ARM',
                    'image_count' => 1720,
                ],
                [
                    'name' => '',
                    'lon' => '',
                    'lat' => '',
                    'iso3' => '',
                    'image_count' => 0,
                ],
            ]),
            $this->rowsByIso3($response->json('data')),
        );
    }

    public function test_versioned_country_image_count_is_bearer_and_unknown_query_irrelevant(): void
    {
        $withoutBearer = $this->getJson('/api/v1/imagery/country-image-count')
            ->assertOk()
            ->json();

        $withBearerAndUnknownQuery = $this->withHeaders([
            'Authorization' => 'Bearer synthetic-irrelevant-token',
        ])->getJson('/api/v1/imagery/country-image-count?country=synthetic&page=99&filter=ignored')
            ->assertOk()
            ->json();

        $this->assertSame($withoutBearer, $withBearerAndUnknownQuery);
    }

    public function test_versioned_country_image_count_optional_global_rate_limit_preserves_exact_envelope_and_headers(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/api/v1/imagery/country-image-count')->assertOk();

        $response = $this->getJson('/api/v1/imagery/country-image-count')
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'message' => ['Too many requests.'],
                'error_code' => 429,
            ]);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_versioned_country_image_count_ignores_conditional_headers_and_emits_no_etag(): void
    {
        $response = $this->withHeaders(['If-None-Match' => '"synthetic-country-count-etag"'])
            ->getJson('/api/v1/imagery/country-image-count')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('ETag'));
    }

    private function insertCountry(
        string $name,
        string $lon,
        string $lat,
        string $iso3,
        int $imageCount,
    ): void {
        Schema::getConnection()->table('country_image_count')->insert([
            'name' => $name,
            'lon' => $lon,
            'lat' => $lat,
            'iso3' => $iso3,
            'image_count' => $imageCount,
        ]);
    }

    private function insertNullCountry(): void
    {
        Schema::getConnection()->table('country_image_count')->insert([
            'name' => null,
            'lon' => null,
            'lat' => null,
            'iso3' => null,
            'image_count' => null,
        ]);
    }

    /**
     * @param  list<CountryImageCountRow>  $rows
     * @return array<string, CountryImageCountRow>
     */
    private function rowsByIso3(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $this->assertSame(['name', 'lon', 'lat', 'iso3', 'image_count'], array_keys($row));
            $this->assertArrayNotHasKey($row['iso3'], $indexed);
            $indexed[$row['iso3']] = $row;
        }

        ksort($indexed);

        return $indexed;
    }

    /** @return list<CountryImageCountRow> */
    private function expectedPopulatedRows(): array
    {
        return [
            [
                'name' => 'Algeria',
                'lon' => '2.63',
                'lat' => '28.16',
                'iso3' => 'DZA',
                'image_count' => 500,
            ],
            [
                'name' => 'Armenia',
                'lon' => '44.56',
                'lat' => '40.53',
                'iso3' => 'ARM',
                'image_count' => 1720,
            ],
        ];
    }
}
