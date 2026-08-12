<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicWriteInputBoundsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');
        Config::set('mapilio.imagery_reports.max_message_length', 2000);

        $this->createTables();
    }

    public function test_report_is_still_accepted_anonymously(): void
    {
        $this->postJson('/api/image-report', [
            'imagery_id' => 500,
            'message' => 'Face is visible in this frame.',
        ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 500)
            ->assertJsonPath('data.description', 'Face is visible in this frame.')
            ->assertJsonPath('data.created_by_id', null);

        $this->assertDatabaseHas('default_image_complaint_complaint', [
            'imagery_id' => 500,
            'description' => 'Face is visible in this frame.',
        ]);
    }

    public function test_report_rejects_a_message_above_the_ceiling(): void
    {
        Config::set('mapilio.imagery_reports.max_message_length', 40);

        $this->postJson('/api/image-report', [
            'imagery_id' => 500,
            'message' => str_repeat('a', 41),
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'message' accepts at most 40 characters!"],
                'error_code' => 400,
            ]);

        $this->assertSame(0, Schema::getConnection()->table('default_image_complaint_complaint')->count());

        // The boundary itself is accepted.
        $this->postJson('/api/image-report', [
            'imagery_id' => 500,
            'message' => str_repeat('a', 40),
        ])->assertOk();
    }

    public function test_report_rejects_imagery_that_does_not_exist(): void
    {
        $this->postJson('/api/image-report', [
            'imagery_id' => 999999,
            'message' => 'Reporting a row that was never uploaded.',
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'imagery_id' does not exist!"],
                'error_code' => 400,
            ]);

        $this->assertSame(0, Schema::getConnection()->table('default_image_complaint_complaint')->count());
    }

    public function test_report_still_accepts_soft_deleted_imagery(): void
    {
        // Someone reporting imagery that was removed after they saw it is a
        // real user, not an abuser. Rejecting them would break a working
        // request to block nothing.
        Schema::getConnection()->table('default_mapilio_imagery')
            ->where('id', 500)
            ->update(['deleted_at' => now()]);

        $this->postJson('/api/image-report', [
            'imagery_id' => 500,
            'message' => 'Reporting imagery that was deleted after capture.',
        ])->assertOk();
    }

    public function test_report_preserves_the_legacy_missing_parameter_contract(): void
    {
        $this->postJson('/api/image-report', ['message' => 'No id supplied.'])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'imagery_id' is required!"],
                'error_code' => 400,
            ]);

        $this->postJson('/api/image-report', ['imagery_id' => 500])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'message' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_report_still_accepts_the_nested_options_payload(): void
    {
        $this->postJson('/api/image-report', [
            'options' => [
                'parameters' => [
                    'imagery_id' => 500,
                    'message' => 'Sent in the legacy nested shape.',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Sent in the legacy nested shape.');
    }

    public function test_login_ignores_fields_the_auth_service_does_not_read(): void
    {
        // Extra fields were already inert; this proves whitelisting them out
        // did not change the outcome for a legitimate client.
        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
            'device_type' => 'mobile',
            'login_type' => 'credentials',
            'unexpected' => ['nested' => 'value'],
        ])
            ->assertOk()
            ->assertJsonPath('id', 10);
    }

    public function test_login_still_accepts_username_and_reports_missing_client_credentials(): void
    {
        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'username' => 'alice',
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('id', 10);

        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    private function createTables(): void
    {
        Schema::create('default_users_users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('username')->nullable();
            $table->string('password');
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_image_complaint_complaint', function ($table): void {
            $table->id();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('imagery_id');
            $table->text('description');
        });

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            'id' => 500,
            'deleted_at' => null,
        ]);

        Schema::getConnection()->table('default_users_users')->insert([
            'id' => 10,
            'email' => 'alice@example.test',
            'username' => 'alice',
            'password' => Hash::make('correct-password'),
            'activated' => true,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
