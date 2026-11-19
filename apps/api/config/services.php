<?php

return [

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

    'whatsapp' => [
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'simulate' => (bool) env('WHATSAPP_SIMULATE', env('APP_ENV') === 'local'),
    ],

    'assistant' => [
        'provider' => env('ASSISTANT_PROVIDER', 'none'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

    'signup' => [
        'min_seconds' => (int) env('SIGNUP_MIN_SECONDS', 4),
        'max_hours' => (int) env('SIGNUP_MAX_HOURS', 12),
        'per_minute_ip' => (int) env('SIGNUP_PER_MINUTE_IP', 5),
        'per_hour_ip' => (int) env('SIGNUP_PER_HOUR_IP', 20),
        'per_hour_email' => (int) env('SIGNUP_PER_HOUR_EMAIL', 5),
    ],

];
