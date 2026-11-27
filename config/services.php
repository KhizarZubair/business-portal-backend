<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional file to locate the credentials.
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

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect_uri' => env('LINKEDIN_REDIRECT_URI', env('APP_URL', 'http://localhost:8000').'/api/v1/integrations/linkedin/callback'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', env('LINKEDIN_SCOPES', 'openid,profile,email'))))),
        'api_version' => env('LINKEDIN_API_VERSION', '202605'),
        'posts_endpoint' => env('LINKEDIN_POSTS_ENDPOINT', 'https://api.linkedin.com/rest/posts'),
    ],

];
