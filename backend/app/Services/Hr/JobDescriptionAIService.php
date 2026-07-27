<?php

namespace App\Services\Hr;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use App\Exceptions\BusinessException;
use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Support\Hr\JobDescriptionPromptBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI Job Description generation — the orchestration layer between the controller
 * and the vendor-neutral AIProviderInterface. All AI logic lives here (never in
 * the controller). Builds the prompt via JobDescriptionPromptBuilder, calls the
 * provider, caches the draft (keeping the previous version until HR accepts),
 * and records the audit trail on the Manpower Request.
 */
class JobDescriptionAIService
{
    private const TTL_MINUTES = 120;

    public function __construct(private AIProviderInterface $ai)
    {
    }

    /**
     * Generate (or regenerate) an AI JD for a Manpower Request.
     * Returns ['draft' => [...], 'previous' => [...]|null].
     */
    public function generate(HrManpowerRequest $mr, User $user, bool $regenerate = false, array $options = []): array
    {
        $prompt = JobDescriptionPromptBuilder::buildPrompt($mr, $user->tenant?->name, $options);

        try {
            $raw = $this->ai->complete($prompt['user'], [
                'system'      => $prompt['system'],
                'max_tokens'  => (int) config('ai.defaults.max_tokens', 1800),
                'temperature' => (float) config('ai.defaults.temperature', 0.6),
            ]);
        } catch (AIException $e) {
            Log::channel('hr')->warning('AI JD generation failed', ['mr_id' => $mr->id, 'error' => $e->getMessage()]);
            throw new BusinessException('AI generation is currently unavailable: '.$e->getMessage(), 503);
        }

        $improve = ! empty($options['improve']);

        // On regenerate/improve, preserve the current draft as "previous" so HR
        // never loses the version they were looking at until they explicitly Replace.
        if (($regenerate || $improve) && Cache::has($this->key($mr, 'current'))) {
            Cache::put($this->key($mr, 'previous'), Cache::get($this->key($mr, 'current')), now()->addMinutes(self::TTL_MINUTES));
        }

        $draft = [
            'content'      => $this->clean($raw),
            'provider'     => $this->ai->name(),
            'model'        => $this->ai->model(),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $user->name,
        ];
        Cache::put($this->key($mr, 'current'), $draft, now()->addMinutes(self::TTL_MINUTES));

        $action = $improve ? 'AI JD Improved' : ($regenerate ? 'AI JD Regenerated' : 'AI JD Generated');
        $mr->recordAudit($action, $user, null, array_filter([
            'provider'   => $draft['provider'], 'model' => $draft['model'],
            'options'    => array_filter(array_diff_key($options, ['current_jd' => 1])) ?: null,
            'ats_before' => $improve ? ($options['ats_before'] ?? null) : null,
        ]));
        Log::channel('hr')->info('AI JD generated', ['mr_id' => $mr->id, 'regenerate' => $regenerate, 'provider' => $draft['provider']]);

        return ['draft' => $draft, 'previous' => Cache::get($this->key($mr, 'previous'))];
    }

    /** Temporarily-cached drafts (current + previous) for a request. */
    public function cachedDrafts(HrManpowerRequest $mr): array
    {
        return [
            'current'  => Cache::get($this->key($mr, 'current')),
            'previous' => Cache::get($this->key($mr, 'previous')),
        ];
    }

    /** Called once HR has accepted an AI JD into the actual Job Description. */
    public function clearCache(HrManpowerRequest $mr): void
    {
        Cache::forget($this->key($mr, 'current'));
        Cache::forget($this->key($mr, 'previous'));
    }

    private function key(HrManpowerRequest $mr, string $slot): string
    {
        return "ai_jd:{$mr->tenant_id}:{$mr->id}:{$slot}";
    }

    /** Strip stray Markdown/HTML the model may emit, so it renders cleanly in the
     *  plain-text (pre-wrap) JD editor. */
    private function clean(string $text): string
    {
        $text = preg_replace('/^#{1,6}\s*/m', '', $text);   // markdown headings
        $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text); // bold
        $text = preg_replace('/`{1,3}/', '', $text);          // code ticks
        $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $text);
        $text = strip_tags($text);

        return trim($text);
    }
}
