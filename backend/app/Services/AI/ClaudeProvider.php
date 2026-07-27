<?php

namespace App\Services\AI;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Claude provider — /v1/messages API. Drop-in alternative to
 * OpenAIProvider; selected via config('ai.provider') = 'claude'. Never fakes
 * output.
 */
class ClaudeProvider implements AIProviderInterface
{
    public function __construct(private array $config = [])
    {
    }

    public function name(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return $this->config['model'] ?? 'claude-sonnet-4-5';
    }

    public function complete(string $prompt, array $options = []): string
    {
        $key = $this->config['api_key'] ?? null;
        if (! $key) {
            throw new AIException('Anthropic API key is not configured (set ANTHROPIC_API_KEY).');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $key,
                'anthropic-version' => $this->config['version'] ?? '2023-06-01',
            ])
                ->timeout((int) config('ai.timeout', 45))
                ->post(rtrim($this->config['base_url'] ?? 'https://api.anthropic.com/v1', '/').'/messages', array_filter([
                    'model'      => $options['model'] ?? $this->model(),
                    'max_tokens' => $options['max_tokens'] ?? config('ai.defaults.max_tokens', 1800),
                    'temperature' => $options['temperature'] ?? config('ai.defaults.temperature', 0.6),
                    'system'     => $options['system'] ?? null,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            throw new AIException('Claude request failed: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new AIException('Claude returned '.$response->status().': '.($response->json('error.message') ?? $response->body()));
        }

        $text = $response->json('content.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new AIException('Claude returned an empty response.');
        }

        return trim($text);
    }
}
