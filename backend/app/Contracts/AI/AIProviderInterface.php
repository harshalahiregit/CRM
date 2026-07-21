<?php

namespace App\Contracts\AI;

/**
 * Vendor-neutral LLM abstraction. Every provider (OpenAI, Claude, Azure,
 * OpenRouter, Gemini, …) implements this, so callers never depend on a vendor.
 * Implementations MUST throw App\Exceptions\AIException on any failure
 * (missing key, HTTP error, empty response) — they must never return fake or
 * partial content silently.
 */
interface AIProviderInterface
{
    /**
     * Complete a prompt and return the model's raw text output.
     *
     * @param  string  $prompt   The user prompt.
     * @param  array   $options  Optional: 'system' (string), 'temperature' (float),
     *                           'max_tokens' (int), 'model' (string override).
     *
     * @throws \App\Exceptions\AIException
     */
    public function complete(string $prompt, array $options = []): string;

    /** Short provider identifier for audit/metadata (e.g. 'openai', 'claude'). */
    public function name(): string;

    /** The model id this provider will use (for audit/metadata). */
    public function model(): string;
}
