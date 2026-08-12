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

    'legacy_import_preflight' => [
        'enabled' => env('MAPILIO_LEGACY_IMPORT_PREFLIGHT_ENABLED', false),
        'table_allowlist' => (($configuredTables = trim((string) env('MAPILIO_LEGACY_IMPORT_PREFLIGHT_TABLES', ''))) === ''
            ? []
            : array_map('trim', explode(',', $configuredTables))),
        'output_directory' => (string) (env('MAPILIO_LEGACY_IMPORT_PREFLIGHT_OUTPUT_DIRECTORY')
            ?: storage_path('app/private/legacy-import-preflight')),
        'postgresql' => [
            'connect_timeout_seconds' => max(1, min(30, (int) env('MAPILIO_LEGACY_IMPORT_PREFLIGHT_CONNECT_TIMEOUT_SECONDS', 5))),
            'statement_timeout_ms' => max(100, min(60000, (int) env('MAPILIO_LEGACY_IMPORT_PREFLIGHT_STATEMENT_TIMEOUT_MS', 5000))),
            'lock_timeout_ms' => max(100, min(10000, (int) env('MAPILIO_LEGACY_IMPORT_PREFLIGHT_LOCK_TIMEOUT_MS', 1000))),
            'max_runtime_ms' => max(1000, min(120000, (int) env('MAPILIO_LEGACY_IMPORT_PREFLIGHT_MAX_RUNTIME_MS', 30000))),
        ],
    ],

    'import_schema_extractor' => [
        'enabled' => env('MAPILIO_IMPORT_SCHEMA_EXTRACTOR_ENABLED', false),
        'source_connection' => env('MAPILIO_IMPORT_SCHEMA_SOURCE_CONNECTION', 'legacy_pgsql'),
        'source_connection_allowlist' => ['legacy_pgsql'],
        'schema' => env('MAPILIO_IMPORT_SCHEMA_SCHEMA'),
        'table' => env('MAPILIO_IMPORT_SCHEMA_TABLE'),
        'output_directory' => (string) (env('MAPILIO_IMPORT_SCHEMA_OUTPUT_DIRECTORY') ?: storage_path('app/private/import-schema-descriptors')),
        'postgresql' => [
            'connect_timeout_seconds' => max(1, min(30, (int) env('MAPILIO_IMPORT_SCHEMA_CONNECT_TIMEOUT_SECONDS', 5))),
            'statement_timeout_ms' => max(100, min(60000, (int) env('MAPILIO_IMPORT_SCHEMA_STATEMENT_TIMEOUT_MS', 5000))),
            'lock_timeout_ms' => max(100, min(10000, (int) env('MAPILIO_IMPORT_SCHEMA_LOCK_TIMEOUT_MS', 1000))),
            'max_runtime_ms' => max(1000, min(120000, (int) env('MAPILIO_IMPORT_SCHEMA_MAX_RUNTIME_MS', 30000))),
        ],
    ],

    'target_schema_extractor' => [
        'enabled' => env('MAPILIO_TARGET_SCHEMA_EXTRACTOR_ENABLED', false),
        'target_connection' => env('MAPILIO_TARGET_SCHEMA_CONNECTION', 'pgsql'),
        'schema' => env('MAPILIO_TARGET_SCHEMA_SCHEMA'),
        'table' => env('MAPILIO_TARGET_SCHEMA_TABLE'),
        'output_directory' => (string) (env('MAPILIO_TARGET_SCHEMA_OUTPUT_DIRECTORY') ?: storage_path('app/private/target-schema-descriptors')),
        'postgresql' => [
            'connect_timeout_seconds' => max(1, min(30, (int) env('MAPILIO_TARGET_SCHEMA_CONNECT_TIMEOUT_SECONDS', 5))),
            'statement_timeout_ms' => max(100, min(60000, (int) env('MAPILIO_TARGET_SCHEMA_STATEMENT_TIMEOUT_MS', 5000))),
            'lock_timeout_ms' => max(100, min(10000, (int) env('MAPILIO_TARGET_SCHEMA_LOCK_TIMEOUT_MS', 1000))),
            'max_runtime_ms' => max(1000, min(120000, (int) env('MAPILIO_TARGET_SCHEMA_MAX_RUNTIME_MS', 30000))),
        ],
    ],

    'local_demo_seeding' => [
        'enabled' => env('MAPILIO_DEMO_SEEDING_ENABLED', false),
    ],

    'observability' => [
        'api_request_logging_enabled' => env('MAPILIO_API_REQUEST_LOGGING_ENABLED', false),
        'slow_request_ms' => max(1, (int) env('MAPILIO_API_SLOW_REQUEST_MS', 1000)),
    ],

    'backup_readiness' => [
        'evidence_path' => env('MAPILIO_BACKUP_EVIDENCE_PATH'),
        'expected_environment' => env('MAPILIO_BACKUP_EXPECTED_ENVIRONMENT'),
        'max_manifest_age_minutes' => env('MAPILIO_BACKUP_MAX_MANIFEST_AGE_MINUTES'),
        'max_backup_age_hours' => env('MAPILIO_BACKUP_MAX_AGE_HOURS'),
        'max_wal_age_minutes' => env('MAPILIO_BACKUP_MAX_WAL_AGE_MINUTES'),
        'max_restore_drill_age_days' => env('MAPILIO_BACKUP_MAX_RESTORE_DRILL_AGE_DAYS'),
        'max_rpo_seconds' => env('MAPILIO_BACKUP_MAX_RPO_SECONDS'),
        'max_rto_seconds' => env('MAPILIO_BACKUP_MAX_RTO_SECONDS'),
    ],

    'image_server' => [
        'cdn_base_url' => rtrim((string) env('MAPILIO_CDN_BASE_URL', 'https://cdn.mapilio.com'), '/'),
        'image_path_prefix' => trim((string) env('MAPILIO_CDN_IMAGE_PATH_PREFIX', 'im'), '/'),
        'mobile_upload_path' => env('MAPILIO_CDN_MOBILE_UPLOAD_PATH', '/api/upload/mobile'),
        'chunk_upload_path' => env('MAPILIO_CDN_CHUNK_UPLOAD_PATH', '/upload/'),
        'hash_contract' => env('MAPILIO_CDN_HASH_CONTRACT', 'encrypted-directory-path'),
    ],

    'image_upload_smoke' => [
        'enabled' => env('MAPILIO_IMAGE_UPLOAD_SMOKE_ENABLED', false),
        'base_url' => env('MAPILIO_IMAGE_UPLOAD_SMOKE_BASE_URL'),
        'allowed_hosts' => env('MAPILIO_IMAGE_UPLOAD_SMOKE_ALLOWED_HOSTS', ''),
        'connect_timeout' => (int) env('MAPILIO_IMAGE_UPLOAD_SMOKE_CONNECT_TIMEOUT', 3),
        'request_timeout' => (int) env('MAPILIO_IMAGE_UPLOAD_SMOKE_REQUEST_TIMEOUT', 20),
        'chunk_size' => (int) env('MAPILIO_IMAGE_UPLOAD_SMOKE_CHUNK_SIZE', 256),
        'poll_attempts' => (int) env('MAPILIO_IMAGE_UPLOAD_SMOKE_POLL_ATTEMPTS', 10),
        'poll_delay_ms' => (int) env('MAPILIO_IMAGE_UPLOAD_SMOKE_POLL_DELAY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Imagery Upload Payload Bounds
    |--------------------------------------------------------------------------
    |
    | Sequence metadata arrives as one element per captured image. The ceiling
    | is a resource guard, not a product limit: it is set well above the
    | largest sequence observed in production (18,824 images) so that no
    | legitimate upload is rejected, while an unbounded payload can no longer
    | exhaust memory. Rows are written in chunks so a long sequence never
    | builds a single oversized statement.
    |
    */

    'imagery_uploads' => [
        'max_points' => max(1, (int) env('MAPILIO_IMAGERY_UPLOAD_MAX_POINTS', 50000)),
        'chunk_size' => max(1, min(5000, (int) env('MAPILIO_IMAGERY_UPLOAD_CHUNK_SIZE', 500))),
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

    'ai_feature_api' => [
        'cache_ttl' => (int) env('MAPILIO_AI_FEATURE_API_CACHE_TTL', 60),
        'stale_while_revalidate' => (int) env('MAPILIO_AI_FEATURE_API_STALE_WHILE_REVALIDATE', 300),
    ],

    'ai_status_projection' => [
        'enabled' => env('MAPILIO_AI_STATUS_PROJECTION_ENABLED', false),
        'queue' => env('MAPILIO_AI_STATUS_PROJECTION_QUEUE', 'ai-status-projections'),
    ],

    'geo_publication' => [
        'registration_enabled' => env('MAPILIO_GEO_PUBLICATION_REGISTRATION_ENABLED', false),
        'preparation_enabled' => env('MAPILIO_GEO_PUBLICATION_PREPARATION_ENABLED', false),
        'delivery_enabled' => env('MAPILIO_GEO_PUBLICATION_DELIVERY_ENABLED', false),
        'queue' => env('MAPILIO_GEO_PUBLICATION_QUEUE', 'geo-publications'),
        'preparation_queue' => env('MAPILIO_GEO_PUBLICATION_PREPARATION_QUEUE', 'geo-publication-preparation'),
        'target' => env('MAPILIO_GEO_PUBLICATION_TARGET', 'canonical_ai_detections'),
        'view' => env('MAPILIO_GEO_PUBLICATION_VIEW', 'mapilio_ai_features_v1'),
        'layer' => env('MAPILIO_GEO_PUBLICATION_LAYER', 'mapilio:ai_features_v1'),
    ],

    'address_enrichment' => [
        'enabled' => env('MAPILIO_ADDRESS_ENRICHMENT_ENABLED', false),
        'dispatch_after_upload' => env('MAPILIO_ADDRESS_DISPATCH_AFTER_UPLOAD', false),
        'endpoint' => env('MAPILIO_ADDRESS_PROVIDER_ENDPOINT'),
        'user_agent' => env('MAPILIO_ADDRESS_PROVIDER_USER_AGENT', 'MapilioBackend/1.0'),
        'queue' => env('MAPILIO_ADDRESS_QUEUE', 'find-address'),
        'connect_timeout' => (int) env('MAPILIO_ADDRESS_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('MAPILIO_ADDRESS_REQUEST_TIMEOUT', 8),
        'max_point_attempts' => (int) env('MAPILIO_ADDRESS_MAX_POINT_ATTEMPTS', 3),
    ],

    'ukm_scoring' => [
        'enabled' => env('MAPILIO_UKM_SCORING_ENABLED', false),
        'dispatch_after_upload' => env('MAPILIO_UKM_DISPATCH_AFTER_UPLOAD', false),
        'queue' => env('MAPILIO_UKM_QUEUE', 'ukm-scoring'),
        'history_months' => (int) env('MAPILIO_UKM_HISTORY_MONTHS', 6),
        'heading_tolerance_degrees' => (float) env('MAPILIO_UKM_HEADING_TOLERANCE', 45),
        'min_distance_meters' => (float) env('MAPILIO_UKM_MIN_DISTANCE_METERS', 1),
        'max_distance_meters' => (float) env('MAPILIO_UKM_MAX_DISTANCE_METERS', 40),
        'max_score' => (float) env('MAPILIO_UKM_MAX_SCORE', 5),
        'max_points_per_sequence' => (int) env('MAPILIO_UKM_MAX_POINTS_PER_SEQUENCE', 10_000),
        'require_spatial_index' => env('MAPILIO_UKM_REQUIRE_SPATIAL_INDEX', true),
        'spatial_index' => env('MAPILIO_UKM_SPATIAL_INDEX', 'ix_imagery_ukm_geography_active'),
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
        'rate_limits' => [
            'password' => env('MAPILIO_MOBILE_AUTH_PASSWORD_RATE_LIMIT', 10),
            'refresh' => env('MAPILIO_MOBILE_AUTH_REFRESH_RATE_LIMIT', 30),
        ],
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
