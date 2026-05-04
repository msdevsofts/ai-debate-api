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
        'timeout' => env('DIFY_API_TIMEOUT', 100),
        'connect_timeout' => env('DIFY_API_CONNECT_TIMEOUT', 10),
    ],

    'discord' => [
        'bot_token' => env('DISCORD_BOT_TOKEN_AI_DEBATE'),
        'bot_tokens' => [
            'ai_debate' => env('DISCORD_BOT_TOKEN_AI_DEBATE'),
            'gemini' => env('DISCORD_BOT_TOKEN_GEMINI'),
            'llama' => env('DISCORD_BOT_TOKEN_LLAMA'),
            'gemma' => env('DISCORD_BOT_TOKEN_GEMMA'),
            'phi' => env('DISCORD_BOT_TOKEN_PHI'),
            'gpt_oss_q2' => env('DISCORD_BOT_TOKEN_GPT_OSS_Q2'),
        ],
        'guild_id' => env('DISCORD_GUILD_ID'),
        'channel_id' => env('DISCORD_CHANNEL_ID'),
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'public_key' => env('DISCORD_PUBLIC_KEY_AI_DEBATE'),
        'public_keys' => [
            'ai_debate' => env('DISCORD_PUBLIC_KEY_AI_DEBATE'),
            'gemini' => env('DISCORD_PUBLIC_KEY_GEMINI'),
            'llama' => env('DISCORD_PUBLIC_KEY_LLAMA'),
            'gemma' => env('DISCORD_PUBLIC_KEY_GEMMA'),
            'phi' => env('DISCORD_PUBLIC_KEY_PHI'),
            'gpt_oss_q2' => env('DISCORD_PUBLIC_KEY_GPT_OSS_Q2'),
        ],
        'bot_ids' => array_filter([
            (string) env('DISCORD_CLIENT_ID_AI_DEBATE') => 'ai_debate',
            (string) env('DISCORD_CLIENT_ID_GEMINI') => 'gemini',
            (string) env('DISCORD_CLIENT_ID_LLAMA') => 'llama',
            (string) env('DISCORD_CLIENT_ID_GEMMA') => 'gemma',
            (string) env('DISCORD_CLIENT_ID_PHI') => 'phi',
            (string) env('DISCORD_CLIENT_ID_GPT_OSS_Q2') => 'gpt_oss_q2',
        ], fn($key) => $key !== ''),
    ],

];
