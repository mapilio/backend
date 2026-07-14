<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileProjectJobsCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');

        $this->createTables();
        $this->seedData();
    }

    public function test_mobile_get_my_jobs_preserves_project_modal_contract(): void
    {
        $login = $this->login('alice@example.test');

        $this->withToken($login->json('access_token'))
            ->getJson('/api/function/projects/job/getMyJobs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 10)
            ->assertJsonPath('data.0.project_key', 'project-istanbul')
            ->assertJsonPath('data.0.assign_id', 10)
            ->assertJsonPath('data.0.user_detail.0.email', 'alice@example.test')
            ->assertJsonPath('data.0.project_detail.marketplace_name', 'Istanbul Capture')
            ->assertJsonPath('data.0.project_detail.marketplace_description', 'Collect street-level imagery in Istanbul.')
            ->assertJsonPath('data.0.project_detail.project_organization_key', 'org-main')
            ->assertJsonPath('data.0.project_detail.project_key', 'project-istanbul')
            ->assertJsonPath('data.1.id', 11)
            ->assertJsonPath('data.1.project_detail.marketplace_name', 'Ankara Capture');
    }

    public function test_mobile_get_my_jobs_requires_valid_bearer_token(): void
    {
        $this->getJson('/api/function/projects/job/getMyJobs')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_mobile_get_my_jobs_returns_empty_array_for_no_jobs(): void
    {
        $login = $this->login('empty@example.test');

        $this->withToken($login->json('access_token'))
            ->getJson('/api/function/projects/job/getMyJobs')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
            ]);
    }

    public function test_versioned_mobile_get_my_jobs_alias_matches_legacy_contract(): void
    {
        $login = $this->login('alice@example.test');

        $legacy = $this->withToken($login->json('access_token'))
            ->getJson('/api/function/projects/job/getMyJobs')
            ->assertOk()
            ->json();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/v1/projects/jobs/mine')
            ->assertOk()
            ->assertExactJson($legacy);
    }

    private function login(string $email)
    {
        return $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => $email,
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

        Schema::create('default_projects_project', function ($table): void {
            $table->id();
            $table->string('marketplace_name')->nullable();
            $table->text('marketplace_description')->nullable();
            $table->string('project_organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_projects_job', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('project_id')->nullable();
            $table->string('project_key')->nullable();
            $table->integer('assign_id')->nullable();
        });
    }

    private function seedData(): void
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
            [
                'id' => 20,
                'email' => 'empty@example.test',
                'username' => 'empty',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Empty Jobs',
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 30,
                'email' => 'other@example.test',
                'username' => 'other',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Other User',
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_projects_project')->insert([
            [
                'id' => 100,
                'marketplace_name' => 'Istanbul Capture',
                'marketplace_description' => 'Collect street-level imagery in Istanbul.',
                'project_organization_key' => 'org-main',
                'project_key' => 'project-istanbul',
                'deleted_at' => null,
            ],
            [
                'id' => 101,
                'marketplace_name' => 'Ankara Capture',
                'marketplace_description' => 'Collect imagery in Ankara.',
                'project_organization_key' => 'org-main',
                'project_key' => 'project-ankara',
                'deleted_at' => null,
            ],
            [
                'id' => 102,
                'marketplace_name' => 'Deleted Capture',
                'marketplace_description' => 'Deleted project.',
                'project_organization_key' => 'org-main',
                'project_key' => 'project-deleted',
                'deleted_at' => '2026-01-03 00:00:00',
            ],
        ]);

        Schema::getConnection()->table('default_projects_job')->insert([
            [
                'id' => 11,
                'sort_order' => 2,
                'created_at' => '2026-01-01 10:00:01',
                'created_by_id' => 1,
                'updated_at' => '2026-01-02 10:00:01',
                'updated_by_id' => 1,
                'deleted_at' => null,
                'project_id' => 101,
                'project_key' => 'project-ankara',
                'assign_id' => 10,
            ],
            [
                'id' => 10,
                'sort_order' => 1,
                'created_at' => '2026-01-01 10:00:00',
                'created_by_id' => 1,
                'updated_at' => '2026-01-02 10:00:00',
                'updated_by_id' => 1,
                'deleted_at' => null,
                'project_id' => 100,
                'project_key' => 'project-istanbul',
                'assign_id' => 10,
            ],
            [
                'id' => 12,
                'sort_order' => 3,
                'created_at' => '2026-01-01 10:00:02',
                'created_by_id' => 1,
                'updated_at' => '2026-01-02 10:00:02',
                'updated_by_id' => 1,
                'deleted_at' => null,
                'project_id' => 100,
                'project_key' => 'project-istanbul',
                'assign_id' => 30,
            ],
            [
                'id' => 13,
                'sort_order' => 4,
                'created_at' => '2026-01-01 10:00:03',
                'created_by_id' => 1,
                'updated_at' => '2026-01-02 10:00:03',
                'updated_by_id' => 1,
                'deleted_at' => '2026-01-03 00:00:00',
                'project_id' => 100,
                'project_key' => 'project-istanbul',
                'assign_id' => 10,
            ],
            [
                'id' => 14,
                'sort_order' => 5,
                'created_at' => '2026-01-01 10:00:04',
                'created_by_id' => 1,
                'updated_at' => '2026-01-02 10:00:04',
                'updated_by_id' => 1,
                'deleted_at' => null,
                'project_id' => 102,
                'project_key' => 'project-deleted',
                'assign_id' => 10,
            ],
        ]);
    }
}
