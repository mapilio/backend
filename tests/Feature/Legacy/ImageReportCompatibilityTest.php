<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImageReportCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');

        $this->createTables();
        $this->seedUsers();
    }

    public function test_image_report_accepts_mobile_nested_payload_and_records_authenticated_user(): void
    {
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/image-report', [
                'options' => [
                    'parameters' => [
                        'imagery_id' => 123,
                        'message' => 'Privacy Violation',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 123)
            ->assertJsonPath('data.description', 'Privacy Violation')
            ->assertJsonPath('data.created_by_id', 10)
            ->assertJsonPath('data.updated_by_id', 10);

        $this->assertDatabaseHas('default_image_complaint_complaint', [
            'imagery_id' => 123,
            'description' => 'Privacy Violation',
            'created_by_id' => 10,
        ]);
    }

    public function test_image_report_keeps_legacy_flat_payload_compatibility(): void
    {
        $this->postJson('/api/image-report', [
            'imagery_id' => 456,
            'message' => 'Low Quality',
        ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 456)
            ->assertJsonPath('data.description', 'Low Quality')
            ->assertJsonPath('data.created_by_id', null);

        $this->assertDatabaseHas('default_image_complaint_complaint', [
            'imagery_id' => 456,
            'description' => 'Low Quality',
            'created_by_id' => null,
        ]);
    }

    public function test_image_report_preserves_missing_parameter_error_shape(): void
    {
        $this->postJson('/api/image-report')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'imagery_id' is required!"],
                'error_code' => 400,
            ]);

        $this->postJson('/api/image-report', [
            'imagery_id' => 123,
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'message' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_versioned_image_report_alias_preserves_negative_contract_without_writing_complaints(): void
    {
        foreach (['/api/image-report', '/api/v1/imagery/reports'] as $path) {
            $this->postJson($path)
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'imagery_id' is required!"],
                    'error_code' => 400,
                ]);
        }

        foreach (['/api/image-report', '/api/v1/imagery/reports'] as $path) {
            $this->postJson($path, ['imagery_id' => 123])
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'message' is required!"],
                    'error_code' => 400,
                ]);
        }

        $this->assertSame(0, Schema::getConnection()->table('default_image_complaint_complaint')->count());
    }

    public function test_versioned_image_report_alias_matches_legacy_write_contract(): void
    {
        $this->postJson('/api/v1/imagery/reports', [
            'options' => [
                'parameters' => [
                    'imagery_id' => 789,
                    'message' => 'Other',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 789)
            ->assertJsonPath('data.description', 'Other');
    }

    private function login()
    {
        return $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();
    }

    private function createTables(): void
    {
        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_image_complaint_complaint', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('imagery_id')->nullable();
            $table->text('description')->nullable();
        });
    }

    private function seedUsers(): void
    {
        Schema::getConnection()->table('default_users_users')->insert([
            [
                'id' => 10,
                'email' => 'alice@example.test',
                'username' => 'alice',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Alice Example',
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
        ]);
    }
}
