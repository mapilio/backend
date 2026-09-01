<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
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

    public function test_versioned_mobile_general_config_prefers_header_token_over_legacy_query_token(): void
    {
        config(['mapilio.mobile_config.token' => 'server-config-token']);

        $this->withHeaders(['X-Mapilio-Config-Token' => 'server-config-token'])
            ->getJson('/api/v1/mobile/config/general?token=wrong-query-token')
            ->assertOk()
            ->assertJsonStructure(['config']);

        $this->flushHeaders();

        $this->withHeaders(['X-Mapilio-Config-Token' => 'wrong-header-token'])
            ->getJson('/api/v1/mobile/config/general?token=server-config-token')
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => ['Forbidden'],
            ]);
    }

    public function test_versioned_mobile_general_config_empty_header_falls_back_to_valid_legacy_query_token(): void
    {
        config(['mapilio.mobile_config.token' => 'server-config-token']);

        $this->withHeaders(['X-Mapilio-Config-Token' => ''])
            ->getJson('/api/v1/mobile/config/general?token=server-config-token')
            ->assertOk()
            ->assertJsonStructure(['config']);
    }

    public function test_versioned_mobile_general_config_allows_access_when_server_token_is_empty_unset_or_non_string(): void
    {
        foreach (['', null, false, true, 123] as $serverToken) {
            config(['mapilio.mobile_config.token' => $serverToken]);

            $this->getJson('/api/v1/mobile/config/general')
                ->assertOk()
                ->assertJsonStructure(['config']);
        }
    }

    public function test_versioned_mobile_general_config_optional_global_rate_limit_preserves_exact_envelope_and_headers(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/api/v1/mobile/config/general')->assertOk();

        $response = $this->getJson('/api/v1/mobile/config/general')
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

    public function test_versioned_mobile_general_config_ignores_conditional_headers_and_emits_no_etag(): void
    {
        $response = $this->withHeaders(['If-None-Match' => '"synthetic-config-etag"'])
            ->getJson('/api/v1/mobile/config/general')
            ->assertOk()
            ->assertJsonStructure(['config']);

        $this->assertFalse($response->headers->has('ETag'));
    }
}
