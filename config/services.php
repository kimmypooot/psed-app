<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // The IMIS portal that signs staff in (ImisSsoController). Unset = off.
    'imis' => [
        'sso_url' => env('IMIS_SSO_URL') ?: null,
        // This app's own host[:port], as registered in IMIS (SSO_HUB_HOSTS).
        'audience' => env('IMIS_SSO_AUDIENCE') ?: (parse_url((string) env('APP_URL'), PHP_URL_HOST).(parse_url((string) env('APP_URL'), PHP_URL_PORT) ? ':'.parse_url((string) env('APP_URL'), PHP_URL_PORT) : '')),
        'portal_url' => env('IMIS_PORTAL_URL') ?: null,
        // IMIS's people directory (GET {api_url}/api/v1/cares/employees, bearer token). Unset = off.
        'api_url' => env('IMIS_API_URL') ?: null,
        'api_token' => env('IMIS_API_TOKEN') ?: null,
    ],

];
