<?php

namespace App\Services\AI;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;

/**
 * Resolves the active AIProviderInterface from config('ai.provider'). Add a new
 * vendor by implementing AIProviderInterface and registering it here — no caller
 * changes. Bound into the container in AppServiceProvider.
 */
class AIProviderFactory
{
    public static function make(?string $provider = null): AIProviderInterface
    {
        $provider = $provider ?: config('ai.provider', 'openai');
        $config   = config("ai.providers.{$provider}", []);

        return match ($provider) {
            'openai' => new OpenAIProvider($config),
            'claude' => new ClaudeProvider($config),
            default  => throw new AIException("Unsupported AI provider '{$provider}'. Set AI_PROVIDER to openai or claude."),
        };
    }
}
