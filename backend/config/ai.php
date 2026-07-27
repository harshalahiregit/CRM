<?php

/*
 * AI provider configuration — vendor-neutral.
 *
 * The app talks to AIProviderInterface; the concrete provider is chosen here at
 * runtime, so switching OpenAI ↔ Claude ↔ Azure/OpenRouter/Gemini is a config
 * change, never a code change. No key set ⇒ generation returns a clean
 * "not configured" error (never fake output).
 */
return [
    // Active provider: 'openai' | 'claude' (add more by implementing AIProviderInterface).
    'provider' => env('AI_PROVIDER', 'openai'),

    'timeout' => (int) env('AI_TIMEOUT', 45),

    'providers' => [
        'openai' => [
            'api_key'  => env('OPENAI_API_KEY'),
            'model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'claude' => [
            'api_key'    => env('ANTHROPIC_API_KEY'),
            'model'      => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
            'base_url'   => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'version'    => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],

        // Azure / OpenRouter reuse the OpenAI-compatible schema — set base_url + key
        // and point 'provider' to a class that extends OpenAIProvider in future.
    ],

    // Defaults applied to every completion (overridable per call).
    'defaults' => [
        'temperature' => (float) env('AI_TEMPERATURE', 0.6),
        'max_tokens'  => (int) env('AI_MAX_TOKENS', 1800),
    ],
];
