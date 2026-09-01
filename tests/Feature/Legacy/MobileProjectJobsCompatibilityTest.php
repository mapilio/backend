<?php

namespace Tests\Feature\Legacy;

use App\Domain\Projects\Actions\CreateMobileProjectJob;
use App\Domain\Projects\Queries\MobileUserJobsQuery;
use App\Http\Controllers\Legacy\Projects\CreateMobileProjectJobController;
use App\Http\Controllers\Legacy\Projects\MobileUserJobsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyMobileAuthFixtures;
use Tests\TestCase;

class MobileProjectJobsCompatibilityTest extends TestCase
{
    use LegacyMobileAuthFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureLegacyMobileAuth();

        $this->createTables();
        $this->seedLegacyUsers();
        $this->seedData();
    }

    public function test_mobile_get_my_jobs_preserves_project_modal_contract(): void
    {
        $login = $this->loginAsLegacyUser('alice');

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
        foreach (['/api/function/projects/job/getMyJobs', '/api/v1/projects/jobs/mine'] as $path) {
            $this->getJson($path)
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => 'Unauthenticated.',
                ]);
        }
    }

    public function test_mobile_project_job_aliases_use_mobile_auth_middleware(): void
    {
        foreach ([
            'api.legacy.projects.jobs.mine',
            'api.legacy.projects.jobs.create',
            'api.v1.projects.jobs.mine',
            'api.v1.projects.jobs.create',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains('mobile.auth', $route->middleware());
        }
    }

    public function test_mobile_get_my_jobs_controller_fails_closed_without_usable_authenticated_user(): void
    {
        $query = $this->createMock(MobileUserJobsQuery::class);
        $query->expects($this->never())->method('get');

        foreach ([null, 'not-a-user', (object) [], (object) ['id' => 0], (object) ['id' => 'not-an-id']] as $user) {
            $request = Request::create('/api/v1/projects/jobs/mine', 'GET');

            if ($user !== null) {
                $request->attributes->set('mapilio_mobile_user', $user);
            }

            $response = app(MobileUserJobsController::class)($request, $query);

            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame(['message' => 'Unauthenticated.'], $response->getData(true));
        }
    }

    public function test_mobile_get_my_jobs_returns_empty_array_for_no_jobs(): void
    {
        $login = $this->loginAsLegacyUser('empty_jobs');

        $this->withToken($login->json('access_token'))
            ->getJson('/api/function/projects/job/getMyJobs')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
            ]);
    }

    public function test_versioned_mobile_get_my_jobs_alias_matches_legacy_contract(): void
    {
        $login = $this->loginAsLegacyUser('alice');

        $legacy = $this->withToken($login->json('access_token'))
            ->getJson('/api/function/projects/job/getMyJobs')
            ->assertOk()
            ->json();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/v1/projects/jobs/mine')
            ->assertOk()
            ->assertExactJson($legacy);
    }

    public function test_mobile_create_job_repeat_call_preserves_duplicate_contract_and_creates_one_active_membership(): void
    {
        $login = $this->loginAsLegacyUser('empty_jobs');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/projects/job/createJob', [
                'options' => [
                    'parameters' => [
                        'id' => 100,
                    ],
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'data' => true,
            ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/projects/job/createJob', [
                'options' => [
                    'parameters' => [
                        'id' => 100,
                    ],
                ],
            ])
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => ['You are a member of this project'],
                'error_code' => 500,
            ]);

        $this->assertDatabaseHas('default_projects_job', [
            'project_id' => 100,
            'project_key' => 'project-istanbul',
            'assign_id' => 20,
            'created_by_id' => 20,
            'updated_by_id' => 20,
        ]);

        $this->assertSame(1, Schema::getConnection()
            ->table('default_projects_job')
            ->where('project_id', 100)
            ->where('assign_id', 20)
            ->whereNull('deleted_at')
            ->count());
    }

    public function test_mobile_create_job_requires_valid_bearer_token(): void
    {
        foreach (['/api/function/projects/job/createJob', '/api/v1/projects/jobs'] as $path) {
            $this->postJson($path, [
                'options' => [
                    'parameters' => [
                        'id' => 100,
                    ],
                ],
            ])
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => 'Unauthenticated.',
                ]);
        }
    }

    public function test_mobile_create_job_controller_fails_closed_without_usable_authenticated_user(): void
    {
        $jobs = $this->createMock(CreateMobileProjectJob::class);
        $jobs->expects($this->never())->method('create');

        foreach ([null, 'not-a-user', (object) [], (object) ['id' => 0], (object) ['id' => 'not-an-id']] as $user) {
            $request = Request::create('/api/v1/projects/jobs', 'POST', [
                'options' => [
                    'parameters' => [
                        'id' => 100,
                    ],
                ],
            ]);

            if ($user !== null) {
                $request->attributes->set('mapilio_mobile_user', $user);
            }

            $response = app(CreateMobileProjectJobController::class)($request, $jobs);

            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame(['message' => 'Unauthenticated.'], $response->getData(true));
        }
    }

    public function test_mobile_create_job_rejects_stale_auth_after_user_is_disabled(): void
    {
        $login = $this->loginAsLegacyUser('empty_jobs');

        Schema::getConnection()->table('default_users_users')->where('id', 20)->update([
            'enabled' => false,
        ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/projects/job/createJob', [
                'options' => [
                    'parameters' => [
                        'id' => 100,
                    ],
                ],
            ])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->assertSame(0, Schema::getConnection()
            ->table('default_projects_job')
            ->where('project_id', 100)
            ->where('assign_id', 20)
            ->count());
    }

    public function test_mobile_create_job_preserves_validation_and_domain_errors(): void
    {
        $login = $this->loginAsLegacyUser('empty_jobs');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/projects/job/createJob')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'id' is required!"],
                'error_code' => 400,
            ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/projects/job/createJob', [
                'options' => [
                    'parameters' => [
                        'id' => 999,
                    ],
                ],
            ])
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => ['Project not found!'],
                'error_code' => 403,
            ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/projects/job/createJob', [
                'options' => [
                    'parameters' => [
                        'id' => 103,
                    ],
                ],
            ])
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => ['This project is not eligible.'],
                'error_code' => 500,
            ]);
    }

    public function test_mobile_create_job_rejects_existing_membership(): void
    {
        $login = $this->loginAsLegacyUser('alice');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/projects/job/createJob', [
                'options' => [
                    'parameters' => [
                        'id' => 100,
                    ],
                ],
            ])
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => ['You are a member of this project'],
                'error_code' => 500,
            ]);
    }

    public function test_versioned_mobile_create_job_alias_matches_legacy_write_contract(): void
    {
        $login = $this->loginAsLegacyUser('empty_jobs');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/projects/jobs', [
                'options' => [
                    'parameters' => [
                        'id' => 101,
                    ],
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'data' => true,
            ]);

        $this->assertDatabaseHas('default_projects_job', [
            'project_id' => 101,
            'project_key' => 'project-ankara',
            'assign_id' => 20,
        ]);
    }

    private function createTables(): void
    {
        $this->createLegacyUsersTable();

        Schema::create('default_projects_project', function ($table): void {
            $table->id();
            $table->string('marketplace_name')->nullable();
            $table->text('marketplace_description')->nullable();
            $table->boolean('is_marketplace')->default(false);
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
        Schema::getConnection()->table('default_projects_project')->insert([
            [
                'id' => 100,
                'marketplace_name' => 'Istanbul Capture',
                'marketplace_description' => 'Collect street-level imagery in Istanbul.',
                'is_marketplace' => true,
                'project_organization_key' => 'org-main',
                'project_key' => 'project-istanbul',
                'deleted_at' => null,
            ],
            [
                'id' => 101,
                'marketplace_name' => 'Ankara Capture',
                'marketplace_description' => 'Collect imagery in Ankara.',
                'is_marketplace' => true,
                'project_organization_key' => 'org-main',
                'project_key' => 'project-ankara',
                'deleted_at' => null,
            ],
            [
                'id' => 102,
                'marketplace_name' => 'Deleted Capture',
                'marketplace_description' => 'Deleted project.',
                'is_marketplace' => true,
                'project_organization_key' => 'org-main',
                'project_key' => 'project-deleted',
                'deleted_at' => '2026-01-03 00:00:00',
            ],
            [
                'id' => 103,
                'marketplace_name' => 'Private Capture',
                'marketplace_description' => 'Private project.',
                'is_marketplace' => false,
                'project_organization_key' => 'org-main',
                'project_key' => 'project-private',
                'deleted_at' => null,
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
