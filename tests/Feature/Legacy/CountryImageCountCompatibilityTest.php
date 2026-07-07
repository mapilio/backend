<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CountryImageCountCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('country_image_count', function ($table): void {
            $table->string('name');
            $table->string('lon');
            $table->string('lat');
            $table->string('iso3', 3);
            $table->integer('image_count');
        });

        $this->insertCountry('Algeria', '2.63', '28.16', 'DZA', 500);
        $this->insertCountry('Armenia', '44.56', '40.53', 'ARM', 1720);
    }

    public function test_legacy_country_image_count_path_preserves_response_shape(): void
    {
        $this->getJson('/api/country-image-count')
            ->assertOk()
            ->assertExactJson([
                'data' => [
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
                ],
            ]);
    }

    public function test_versioned_country_image_count_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/country-image-count')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/country-image-count')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
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
}
