<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mapilio Platform Identity
    |--------------------------------------------------------------------------
    |
    | The modern backend preserves external API behavior where needed, but the
    | internal architecture is organized around Mapilio domains instead of
    | PyroCMS modules or Streams add-ons.
    |
    */

    'service_name' => env('MAPILIO_SERVICE_NAME', 'mapilio-modern-backend'),

    'legacy_api_contract' => env('MAPILIO_LEGACY_API_CONTRACT', 'legacy-v1-behavior'),

    'legacy_database_connection' => env('MAPILIO_LEGACY_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),

    'image_server' => [
        'cdn_base_url' => rtrim((string) env('MAPILIO_CDN_BASE_URL', 'https://cdn.mapilio.com'), '/'),
        'image_path_prefix' => trim((string) env('MAPILIO_CDN_IMAGE_PATH_PREFIX', 'im'), '/'),
        'mobile_upload_path' => env('MAPILIO_CDN_MOBILE_UPLOAD_PATH', '/api/upload/mobile'),
        'chunk_upload_path' => env('MAPILIO_CDN_CHUNK_UPLOAD_PATH', '/upload/'),
        'hash_contract' => env('MAPILIO_CDN_HASH_CONTRACT', 'encrypted-directory-path'),
    ],

    'ai_prediction' => [
        'enabled' => env('MAPILIO_AI_PREDICTION_ENABLED', false),
        'dispatch_after_upload' => env('MAPILIO_AI_DISPATCH_AFTER_UPLOAD', false),
        'endpoint' => env('MAPILIO_AI_PREDICTION_ENDPOINT'),
        'config_url' => env('MAPILIO_AI_PREDICTION_CONFIG_URL'),
        'token' => env('MAPILIO_AI_PREDICTION_TOKEN'),
        'queue' => env('MAPILIO_AI_PREDICTION_QUEUE', 'prediction'),
        'connect_timeout' => (int) env('MAPILIO_AI_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('MAPILIO_AI_REQUEST_TIMEOUT', 30),
        'reservation_ttl' => (int) env('MAPILIO_AI_RESERVATION_TTL', 900),
    ],

    'ai_callback' => [
        'enabled' => env('MAPILIO_AI_CALLBACK_ENABLED', false),
        'signing_secret' => env('MAPILIO_AI_CALLBACK_SIGNING_SECRET'),
        'timestamp_tolerance' => (int) env('MAPILIO_AI_CALLBACK_TIMESTAMP_TOLERANCE', 300),
        'nonce_retention' => (int) env('MAPILIO_AI_CALLBACK_NONCE_RETENTION', 86400),
        'max_payload_bytes' => (int) env('MAPILIO_AI_CALLBACK_MAX_PAYLOAD_BYTES', 5_242_880),
        'max_features' => (int) env('MAPILIO_AI_CALLBACK_MAX_FEATURES', 100_000),
        'accepted_statuses' => ['SUCCESS', 'ERROR'],
        'queue' => env('MAPILIO_AI_CALLBACK_QUEUE', 'ai-callbacks'),
    ],

    'ai_result_persistence' => [
        'enabled' => env('MAPILIO_AI_RESULT_PERSISTENCE_ENABLED', false),
        'queue' => env('MAPILIO_AI_RESULT_PERSISTENCE_QUEUE', 'ai-results'),
        'max_matches_per_feature' => (int) env('MAPILIO_AI_MAX_MATCHES_PER_FEATURE', 1000),
        'max_attributes_bytes' => (int) env('MAPILIO_AI_MAX_ATTRIBUTES_BYTES', 131_072),
        'max_segmentation_bytes' => (int) env('MAPILIO_AI_MAX_SEGMENTATION_BYTES', 1_048_576),
    ],

    'mobile_config' => [
        'token' => env('MAPILIO_MOBILE_CONFIG_TOKEN'),
        'general' => [
            'isMarketOpen' => env('MAPILIO_MOBILE_MARKET_OPEN', false),
            'isChallengeOpen' => env('MAPILIO_MOBILE_CHALLENGE_OPEN', false),
            'leaderboard' => [
                'challengeDescEN' => env('MAPILIO_MOBILE_CHALLENGE_DESC_EN', 'Challenge has begun! Collect and win the most points between <0>01.03.2023 - 31.05.2023</0>'),
                'challengeDescTR' => env('MAPILIO_MOBILE_CHALLENGE_DESC_TR', 'Mucadele basladi! <0>01.03.2023 - 31.05.2023</0> arasinda en cok puani toplayin ve kazanin'),
                'challengeDates' => env('MAPILIO_MOBILE_CHALLENGE_DATES', '2023-03-01,2023-05-31'),
                'challengeURL' => env('MAPILIO_MOBILE_CHALLENGE_URL', 'https://mapilio.com'),
                'isChallengeOpen' => env('MAPILIO_MOBILE_CHALLENGE_OPEN', false),
                'infoBoxDescTR' => env('MAPILIO_MOBILE_INFOBOX_DESC_TR', 'Yarisma <0>31.05.2023</0> tarihinde sona erdi. Bu tarihten sonra yapilan cekimlerin puanlari tum zamanlar bolumune yansitilacaktir.'),
                'infoBoxDescEN' => env('MAPILIO_MOBILE_INFOBOX_DESC_EN', 'The challenge has ended as of <0>31.05.2023</0>. Scores of the captures after this date will be reflected in the all time section.'),
                'isInfoBoxOpen' => env('MAPILIO_MOBILE_INFOBOX_OPEN', false),
                'showWeek' => env('MAPILIO_MOBILE_SHOW_WEEK', true),
            ],
            'socialLogin' => [
                'isFacebookEnabled' => env('MAPILIO_MOBILE_FACEBOOK_LOGIN_ENABLED', true),
                'isGoogleEnabled' => env('MAPILIO_MOBILE_GOOGLE_LOGIN_ENABLED', true),
                'isAppleEnabled' => env('MAPILIO_MOBILE_APPLE_LOGIN_ENABLED', true),
                'isOSMEnabled' => env('MAPILIO_MOBILE_OSM_LOGIN_ENABLED', true),
            ],
            'versions' => [
                'android' => [
                    'version' => env('MAPILIO_MOBILE_ANDROID_VERSION', '1.0.56'),
                    'minVersion' => env('MAPILIO_MOBILE_ANDROID_MIN_VERSION', '0'),
                ],
                'ios' => [
                    'version' => env('MAPILIO_MOBILE_IOS_VERSION', '1.2.0'),
                    'minVersion' => env('MAPILIO_MOBILE_IOS_MIN_VERSION', '0'),
                ],
            ],
            'map' => [
                'iosToken' => env('MAPILIO_MOBILE_IOS_MAP_TOKEN', ''),
                'androidToken' => env('MAPILIO_MOBILE_ANDROID_MAP_TOKEN', ''),
            ],
            'mapTokens' => [
                'iosToken' => env('MAPILIO_MOBILE_IOS_MAP_TOKEN', ''),
                'androidToken' => env('MAPILIO_MOBILE_ANDROID_MAP_TOKEN', ''),
            ],
            'osmModal' => [
                'titleEN' => env('MAPILIO_MOBILE_OSM_MODAL_TITLE_EN', 'Your email safe with us!'),
                'descriptionEN' => env('MAPILIO_MOBILE_OSM_MODAL_DESC_EN', "We'll never share it with any third-party providers."),
                'titleTR' => env('MAPILIO_MOBILE_OSM_MODAL_TITLE_TR', 'E-postaniz bizimle guvende!'),
                'descriptionTR' => env('MAPILIO_MOBILE_OSM_MODAL_DESC_TR', 'Ucuncu taraf saglayicilarla asla paylasmayacagiz.'),
            ],
        ],
    ],

    'mobile_auth' => [
        'client_id' => env('MAPILIO_MOBILE_AUTH_CLIENT_ID'),
        'client_secret' => env('MAPILIO_MOBILE_AUTH_CLIENT_SECRET'),
        'signing_key' => env('MAPILIO_MOBILE_AUTH_SIGNING_KEY', env('APP_KEY')),
        'access_token_ttl' => (int) env('MAPILIO_MOBILE_ACCESS_TOKEN_TTL', 3600),
        'refresh_token_ttl' => (int) env('MAPILIO_MOBILE_REFRESH_TOKEN_TTL', 36000),
        'default_profile_photo_url' => env('MAPILIO_DEFAULT_PROFILE_PHOTO_URL', 'https://mapilio.com/app/default_avatar.png'),
        'onesignal_rest_api_key' => env('MAPILIO_ONESIGNAL_REST_API_KEY'),
    ],

    'leaderboard' => [
        'limit' => (int) env('MAPILIO_LEADERBOARD_LIMIT', 30),
        'public_role_slugs' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('MAPILIO_LEADERBOARD_PUBLIC_ROLE_SLUGS', 'admin,blog_editor,contributor,member,org_admin,user')),
        ))),
        'excluded_role_slugs' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('MAPILIO_LEADERBOARD_EXCLUDED_ROLE_SLUGS', '')),
        ))),
    ],

    'domains' => [
        'identity_access',
        'imagery_sequences',
        'ai_jobs_predictions',
        'billing_catalog',
        'inventory_features',
        'imagery_reports',
        'public_content',
        'projects',
        'gamification',
        'geo_publishing',
        'operations_dashboard',
        'community_integrations',
    ],
];
