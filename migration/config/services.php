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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'swift_fleet' => [
        'image_url' => env('SWIFT_FLEET_IMAGE_URL'),
    ],

    'swift_legacy' => [
        'media_url' => env('LEGACY_SWIFT_MEDIA_URL'),
        'uploads_path' => env('LEGACY_SWIFT_UPLOADS_PATH', base_path('../admin.swift.rent/storage/app/public/uploads')),
        'allowed_media_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LEGACY_MEDIA_ALLOWED_HOSTS', '')),
        ))),
    ],

    'admin_sync' => [
        'enabled' => (bool) env('ADMIN_BIDIRECTIONAL_SYNC_ENABLED', false),
        'vendor' => env('ADMIN_BIDIRECTIONAL_SYNC_VENDOR', 'swift'),
    ],

];
