<?php

return [
    'pricing' => [
        // OpenAI
        'openai:gpt-5'       => ['input_per_million' => 1.25, 'output_per_million' => 10.00], // <-- METTI i tuoi prezzi REALI
        'openai:gpt-4o-mini' => ['input_per_million' => 0.60, 'output_per_million' => 2.40], // <-- idem
        'openai:o3' => [
            'input_per_million'  => 2,   // TODO: metti i valori veri quando li hai
            'output_per_million' => 8,
        ],
        'openai:o3-pro' => [
            'input_per_million'  => 20,
            'output_per_million' => 80,
        ],

        // Anthropic
        'anthropic:claude-3-5-haiku' => ['input_per_million' => 0.80, 'output_per_million' => 4.00], // aggiorna

        // Google
        'google:gemini-1.5-flash'    => ['input_per_million' => 0.075, 'output_per_million' => 0.075], // aggiorna
    ],
];

