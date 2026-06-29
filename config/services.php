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

    'meta_ads' => [
        'api_version' => env('META_API_VERSION', 'v25.0'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'ad_account_ids' => array_values(array_filter(array_map('trim', explode(',', env('META_AD_ACCOUNT_IDS', ''))))),
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
    ],

    'google_ads' => [
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v22'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),
        'customer_ids' => array_values(array_filter(array_map('trim', explode(',', env('GOOGLE_ADS_CUSTOMER_IDS', ''))))),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
    ],

    'informes_auth' => [
        'enabled' => filter_var(env('INFORMES_AUTH_ENABLED', true), FILTER_VALIDATE_BOOL),
        'email' => env('INFORMES_AUTH_EMAIL', env('INFORMES_AUTH_USER')),
        'user' => env('INFORMES_AUTH_USER'),
        'password' => env('INFORMES_AUTH_PASSWORD'),
        'remember_days' => (int) env('INFORMES_AUTH_REMEMBER_DAYS', 30),
    ],

    'report_admin' => [
        'email' => env('REPORT_ADMIN_EMAIL'),
        'password' => env('REPORT_ADMIN_PASSWORD'),
        'name' => env('REPORT_ADMIN_NAME', 'Administrador informes'),
    ],

    'internal_reviews' => [
        'endpoint' => env('INTERNAL_REVIEWS_ENDPOINT', 'https://app.hrmotor.com/api/internal/google-reviews/count'),
        'user' => env('INTERNAL_REVIEWS_USER', 'hrmotor-informes'),
        'password' => env('INTERNAL_REVIEWS_PASSWORD', 'frY5v&gQ8*UR*1Uq99t9kCrPo'),
        'timeout' => (int) env('INTERNAL_REVIEWS_TIMEOUT', 20),
    ],

    'commissions_api' => [
        'user' => env('COMMISSIONS_API_USER', 'hrmotor-comisiones'),
        'password' => env('COMMISSIONS_API_PASSWORD', 'cambiar-en-produccion'),
    ],

];
