<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'purchase_chat_id' => env('TELEGRAM_PURCHASE_CHAT_ID'),
        'ai_chat_id' => env('TELEGRAM_AI_CHAT_ID', env('TELEGRAM_PURCHASE_CHAT_ID')),
    ],

    'airllm' => [
        'base_url' => env('AIRLLM_BASE_URL', 'http://127.0.0.1:8100'),
    ],

    'marking' => [
        // true = mock Честный Знак (CI / local); false = live GIS MT
        'mock_mode' => filter_var(env('MARKING_MOCK_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'api_url' => env('CHESTNY_ZNAK_API_URL', 'https://trueapi.ruba.ru/api/v3'),
        'token' => env('CHESTNY_ZNAK_API_TOKEN'),
    ],

];
