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

    'sales_server' => [
        'api_url'      => env('SALES_SERVER_API_URL'),
        'api_token'    => env('SALES_SERVER_API_TOKEN'),
        'sync_enabled' => env('SALES_SERVER_SYNC_ENABLED', false),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack'     => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'ariya_crm' => [
        'base_url' => env('ARIYA_CRM_BASE_URL', 'https://crm.ariyajanebi.ir/public'),
        'token'    => env('ARIYA_CRM_TOKEN'),
    ],

    'external_sync' => [
        'base_url' => env('EXTERNAL_SYNC_BASE_URL', 'https://crm.ariyajanebi.ir'),
        'token'    => env('EXTERNAL_SYNC_TOKEN'),
    ],

    'site' => [
        'base_url' => env('SITE_BASE_URL'),
    ],

    'telegram_daily_report' => [
        'webhook_url' => env('TELEGRAM_DAILY_REPORT_WEBHOOK_URL'),
        'webhook_secret' => env('TELEGRAM_DAILY_REPORT_WEBHOOK_SECRET'),
        'chat_id' => env('TELEGRAM_DAILY_REPORT_CHAT_ID'),
        'cheque_due_days' => (int) env('TELEGRAM_DAILY_REPORT_CHEQUE_DUE_DAYS', 7),
    ],
];
