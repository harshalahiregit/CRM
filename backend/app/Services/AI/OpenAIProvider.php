<?php

namespace App\Services\AI;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI (and OpenAI-compatible: Azure, OpenRouter, Together, …) provider.
 * Talks to the /chat/completions endpoint. Never returns fake content — throws
 * AIException when unconfigured or on any API error.
 */
class OpenAIProvider implements AIProviderInterface
{
    public function __construct(private array $config = [])
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->config['model'] ?? 'gpt-4o-mini';
    }

    public function complete(string $prompt, array $options = []): string
    {
        $key = $this->config['api_key'] ?? null;
        if (! $key) {
            throw new AIException('OpenAI API key is not configured (set OPENAI_API_KEY).');
        }

        $messages = [];
        if (! empty($options['system'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system']];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('ai.timeout', 45))
                ->post(rtrim($this->config['base_url'] ?? 'https://api.openai.com/v1', '/').'/chat/completions', [
                    'model'       => $options['model'] ?? $this->model(),
                    'messages'    => $messages,
                    'temperature' => $options['temperature'] ?? config('ai.defaults.temperature', 0.6),
                    'max_tokens'  => $options['max_tokens'] ?? config('ai.defaults.max_tokens', 1800),
                ]);
        } catch (\Throwable $e) {
            throw new AIException('OpenAI request failed: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new AIException('OpenAI returned '.$response->status().': '.($response->json('error.message') ?? $response->body()));
        }

        $text = $response->json('choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            throw new AIException('OpenAI returned an empty response.');
        }

        return trim($text);
    }
}
