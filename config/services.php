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
    'ariya_crm' => [
    'base_url' => env('ARIYA_CRM_BASE_URL', 'https://crm.ariyajanebi.ir/public'),
    'token'    => env('ARIYA_CRM_TOKEN'),
    ],

    'external_sync' => [
        'base_url' => env('EXTERNAL_SYNC_BASE_URL', 'https://crm.ariyajanebi.ir'),
        'token' => env('EXTERNAL_SYNC_TOKEN'),
    ],

    'crm' => [
        'base_url' => env('CRM_BASE_URL'),
        'users_endpoint' => env('CRM_USERS_ENDPOINT', '/external/users'),
        'api_token' => env('CRM_API_TOKEN'),
        'sync_enabled' => env('CRM_SYNC_ENABLED', true),
        'timeout' => env('CRM_SYNC_TIMEOUT', 30),
        'connect_timeout' => env('CRM_CONNECT_TIMEOUT', 10),
        'client_timeout' => env('CRM_CLIENT_TIMEOUT', 5),
        'client_connect_timeout' => env('CRM_CLIENT_CONNECT_TIMEOUT', 3),
        'verify_ssl' => env('CRM_SYNC_VERIFY_SSL', true),
        'client_token_url' => env('CRM_CLIENT_TOKEN_URL', 'https://crm.ariyajanebi.ir/api/token-for-client'),
        'client_secret' => env('CRM_CLIENT_SECRET'),
    ],
'ariya_crm' => [
    'base_url'            => env('ARIYA_CRM_BASE_URL', 'https://api.ariyajanebi.ir'),
    'products_url'        => env('ARIYA_CRM_PRODUCTS_URL', 'https://api.ariyajanebi.ir/v1/front/products'),
    'token'               => env('ARIYA_CRM_TOKEN'),
    'default_category_id' => env('ARIYA_DEFAULT_CATEGORY_ID', 1),
],
];
