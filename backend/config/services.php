<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-7'),
    ],

    // Zum Rails — Interac e-Transfer / EFT in from parents, payouts out to staff.
    // Dark until all three of base_url, username and password are set: ZumRails::configured()
    // is false without them and every call returns null rather than throwing.
    // The base URL is deliberately NOT defaulted — their docs only ever write {{env}},
    // and guessing a payments endpoint is not a thing to do.
    'zumrails' => [
        'base_url' => env('ZUMRAILS_BASE_URL'),
        'username' => env('ZUMRAILS_USERNAME'),
        'password' => env('ZUMRAILS_PASSWORD'),
        'webhook_secret' => env('ZUMRAILS_WEBHOOK_SECRET'),
        // Their reference documents no refund route. Set this once Zum confirm one,
        // using {id} for the transaction id, e.g. 'api/transaction/{id}/refund'.
        // Empty means refunds are recorded but never sent.
        'refund_path' => env('ZUMRAILS_REFUND_PATH', ''),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT', env('APP_URL').'/api/v1/auth/social/google/callback'),
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT', env('APP_URL').'/api/v1/auth/social/facebook/callback'),
    ],
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT', env('APP_URL').'/api/v1/auth/social/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    // FCM push. Read via config() so it survives `php artisan config:cache`
    // (env() returns null outside config files once config is cached — that
    // silently broke "FCM not configured" push after a config:cache).
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
        'project_id'  => env('FCM_PROJECT_ID'),
    ],
];
