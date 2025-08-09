<?php

return [
    'providers' => [
        'openai' => [
            'base_url' => env('OPENAI_BASE', 'https://api.openai.com/v1'),
            'api_key'  => env('OPENAI_API_KEY'),      // <-- NOME variabile, non la chiave
        ],
        'anthropic' => [
            'base_url' => env('ANTHROPIC_BASE', 'https://api.anthropic.com'),
            'api_key'  => env('ANTHROPIC_API_KEY'),   // <-- NOME variabile
            'version'  => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],
        'google' => [
            'base_url' => env('GOOGLE_AI_BASE', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key'  => env('GOOGLE_API_KEY'),      // <-- NOME variabile
        ],
    ],
];
