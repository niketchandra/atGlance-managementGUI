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

    'atglance_license' => [
        // Licence endpoints on atglance.live. verify: installer "Verify" button (check only).
        // activate: installer submit and Admin Settings > Licence (links the licence to this console and org).
        'verify_url' => env('ATGLANCE_LICENSE_VERIFY_URL', 'https://atglance.live/api/licenses/verify'),
        'activate_url' => env('ATGLANCE_LICENSE_ACTIVATE_URL', 'https://atglance.live/api/licenses/activate'),
        'portal_url' => env('ATGLANCE_LICENSE_PORTAL_URL', 'https://atglance.live'),
    ],

];
