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

    'mailgun'                      => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark'                     => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses'                          => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'default_schema_string_length' => (int) env('DEFAULT_SCHEMA_STRING_LENGTH', 255),

    'theme'                        => (int) env('DEFAULT_THEME', 1),

    'default_status'               => [
        'active_text'   => 'active',
        'inactive_text' => 'inactive',
        'active_bool'   => true,
        'inactive_bool' => false,
        'active_int'    => 1,
        'inactive_int'  => 0,
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_KEY'),
    ],

    // Mobile social login (Google / Apple)
    // GOOGLE_CLIENT_ID can be comma-separated for Android + iOS client IDs.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // APPLE_CLIENT_ID = iOS bundle id and/or Services ID (comma-separated OK via APPLE_CLIENT_IDS)
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    'pusher' => [
        'key' => env('PUSHER_APP_KEY'),
        'cluster' => env('PUSHER_APP_CLUSTER', 'ap2'),
    ],
];
