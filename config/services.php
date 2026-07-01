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

    'uazapi' => [
        'base_url' => env('UAZAPI_BASE_URL'),
        'key'      => env('UAZAPI_KEY'),
    ],

    'openrouter' => [
        'key'             => env('OPENROUTER_KEY'),
        'modelo_simples'  => env('OPENROUTER_MODELO_SIMPLES', 'openai/gpt-4o-mini'),
        'modelo_complexo' => env('OPENROUTER_MODELO_COMPLEXO', 'anthropic/claude-3.5-haiku-20241022'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8000/google/callback'),
    ],

];
