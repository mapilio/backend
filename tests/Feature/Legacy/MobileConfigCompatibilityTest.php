<?php

namespace Tests\Feature\Legacy;

use Tests\TestCase;

class MobileConfigCompatibilityTest extends TestCase
{
    public function test_legacy_mobile_general_config_preserves_expected_wrapper(): void
    {
        $this->getJson('/config/general')
            ->assertOk()
            ->assertJsonStructure([
                'config' => [
                    'isMarketOpen',
                    'isChallengeOpen',
                    'leaderboard' => [
                        'challengeDescEN',
                        'challengeDescTR',
                        'challengeDates',
                        'challengeURL',
                        'isChallengeOpen',
                        'infoBoxDescTR',
                        'infoBoxDescEN',
                        'isInfoBoxOpen',
                        'showWeek',
                    ],
                    'socialLogin' => [
                        'isFacebookEnabled',
                        'isGoogleEnabled',
                        'isAppleEnabled',
                        'isOSMEnabled',
                    ],
                    'versions' => [
                        'android' => [
                            'version',
                            'minVersion',
                        ],
                        'ios' => [
                            'version',
                            'minVersion',
                        ],
                    ],
                    'map' => [
                        'iosToken',
                        'androidToken',
                    ],
                    'mapTokens' => [
                        'iosToken',
                        'androidToken',
                    ],
                    'osmModal' => [
                        'titleEN',
                        'descriptionEN',
                        'titleTR',
                        'descriptionTR',
                    ],
                ],
            ])
            ->assertJsonPath('config.leaderboard.challengeDates', '2023-03-01,2023-05-31')
            ->assertJsonPath('config.socialLogin.isOSMEnabled', true)
            ->assertJsonPath('config.versions.ios.version', '1.2.0');
    }

    public function test_mobile_general_config_token_is_enforced_when_configured(): void
    {
        config(['mapilio.mobile_config.token' => 'test-token']);

        $this->getJson('/config/general')
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => ['Forbidden'],
            ]);

        $this->getJson('/config/general?token=test-token')
            ->assertOk()
            ->assertJsonStructure(['config']);
    }

    public function test_versioned_mobile_general_config_alias_matches_legacy_payload(): void
    {
        $legacy = $this->getJson('/config/general')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/mobile/config/general')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }
}
