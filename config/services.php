<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mailcoach' => [
        'base_url' => env('MAPILIO_MAILCOACH_BASE_URL'),
        'token' => env('MAPILIO_MAILCOACH_API_TOKEN'),
        'list_id' => env('MAPILIO_MAILCOACH_LIST_ID'),
        'skip_confirmation' => env('MAPILIO_MAILCOACH_SKIP_CONFIRMATION', true),
        'connect_timeout' => (int) env('MAPILIO_MAILCOACH_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('MAPILIO_MAILCOACH_REQUEST_TIMEOUT', 8),
    ],

];
