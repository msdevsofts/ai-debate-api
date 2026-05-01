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

    'dify' => [
        'base_url' => env('DIFY_API_BASE_URL', 'http://10.10.1.10/v1'),
        'api_key' => env('DIFY_API_KEY'),
    ],

    'discord' => [
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'bot_tokens' => [
            'gemini' => env('DISCORD_BOT_TOKEN_GEMINI'),
            'llama' => env('DISCORD_BOT_TOKEN_LLAMA'),
            'gemma' => env('DISCORD_BOT_TOKEN_GEMMA'),
            'phi' => env('DISCORD_BOT_TOKEN_PHI'),
        ],
        'guild_id' => env('DISCORD_GUILD_ID'),
        'channel_id' => env('DISCORD_CHANNEL_ID'),
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'public_key' => env('DISCORD_PUBLIC_KEY'),
        'public_keys' => [
            'gemini' => env('DISCORD_PUBLIC_KEY_GEMINI'),
            'llama' => env('DISCORD_PUBLIC_KEY_LLAMA'),
            'gemma' => env('DISCORD_PUBLIC_KEY_GEMMA'),
            'phi' => env('DISCORD_PUBLIC_KEY_PHI'),
        ],
        'bot_ids' => [
            env('DISCORD_BOT_ID_GEMINI') => 'gemini',
            env('DISCORD_BOT_ID_LLAMA') => 'llama',
            env('DISCORD_BOT_ID_GEMMA') => 'gemma',
            env('DISCORD_BOT_ID_PHI') => 'phi',
        ],
    ],

];
