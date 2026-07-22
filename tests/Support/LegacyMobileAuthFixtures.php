<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

trait LegacyMobileAuthFixtures
{
    protected function configureLegacyMobileAuth(): void
    {
        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');
    }

    protected function createLegacyUsersTable(): void
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
    }

    /**
     * @param  list<string>  $fixtureKeys
     */
    protected function seedLegacyUsers(array $fixtureKeys = ['alice', 'empty_jobs', 'other']): void
    {
        $users = $this->legacyUserFixtures();

        Schema::getConnection()->table('default_users_users')->insert(array_map(
            static fn (string $fixtureKey): array => $users[$fixtureKey],
            $fixtureKeys,
        ));
    }

    protected function loginAsLegacyUser(string $fixtureKey): TestResponse
    {
        $user = $this->legacyUserFixtures()[$fixtureKey];

        return $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => $user['email'],
            'password' => 'correct-password',
        ])->assertOk();
    }

    /**
     * @return array<string, array<string, bool|int|string|null>>
     */
    private function legacyUserFixtures(): array
    {
        return [
            'alice' => [
                'id' => 10,
                'email' => 'alice@example.test',
                'username' => 'alice',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Alice Example',
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
            'empty_jobs' => [
                'id' => 20,
                'email' => 'empty@example.test',
                'username' => 'empty',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Empty Jobs',
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
            'other' => [
                'id' => 30,
                'email' => 'other@example.test',
                'username' => 'other',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Other User',
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
        ];
    }
}
