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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'enabled' => env('TURNSTILE_ENABLED', false),
    ],

    'tagihan_ws' => [
        'url' => env('WS_TAGIHAN_URL', 'http://103.23.103.43/WEB_TAGIHAN_PROJECT/WS_TAGIHAN_AISYIYAH_JATINOM/index.php'),
    ],

    'qris' => [
        'url' => env('QRIS_URL', 'http://103.23.103.43/qris/lazizmu_diy/server.php'),
        'jwt_key' => env('QRIS_JWT_KEY', 'TokenJWT_BMI_ICT'),
        'account_no' => env('QRIS_ACCOUNT_NO', '5080010295'),
        'mitra_customer_id' => env('QRIS_MITRA_CUSTOMER_ID', 'ISLAMIC CENTER SMG451061'),
        'min_amount' => (int) env('QRIS_MIN_AMOUNT', 10000),
        'ttl_minutes' => (int) env('QRIS_TTL_MINUTES', 15),
    ],

];
