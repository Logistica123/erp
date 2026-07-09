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

    'arca_gateway' => [
        'url' => env('ARCA_GATEWAY_URL', 'http://127.0.0.1:8000'),
        'client_id' => env('ARCA_CLIENT_ID'),
        'api_key' => env('ARCA_API_KEY'),
        'timeout' => env('ARCA_GATEWAY_TIMEOUT', 60),
    ],

    // v1.54 — webhooks de sync de facturas de compra con DistriApp.
    'distriapp' => [
        'webhook_secret' => env('DISTRIAPP_WEBHOOK_SECRET'),
        'base_url' => env('DISTRIAPP_BASE_URL'),
    ],

];
