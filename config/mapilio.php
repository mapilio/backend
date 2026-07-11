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
        'public_content',
        'projects',
        'gamification',
        'geo_publishing',
        'operations_dashboard',
        'community_integrations',
    ],
];
