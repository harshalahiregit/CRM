<?php

namespace App\Services\Hr;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use App\Exceptions\BusinessException;
use App\Models\Hr\HrInterviewQuestion;
use App\Models\Hr\HrJobPosting;
use App\Models\User;
use App\Support\Hr\InterviewQuestionPromptBuilder;
use App\Support\Hr\SkillMatcher;
use Illuminate\Support\Facades\Log;

/**
 * #10 — "no AI generated question relevant to profile".
 *
 * Reuses the EXISTING vendor-neutral AI layer: the same AIProviderInterface that
 * JobDescriptionAIService uses, resolved through the same container binding, so a
 * tenant that switches from OpenAI to Claude switches both at once. There is no
 * second provider abstraction and no HTTP call in this file.
 *
 * Generation NEVER writes to the bank. It returns drafts for a human to review,
 * edit and then save — which is what makes "Generate → Regenerate → Manual edit"
 * a real review step rather than a machine filling the bank unattended.
 */
class InterviewQuestionAIService
{
    public function __construct(private AIProviderInterface $ai)
    {
    }

    /**
     * Draft questions from a role profile.
     *
     * @param  array  $input  job_posting_id | job_description, designation,
     *                        skills, experience_min/max, count, types, difficulty
     * @return array{questions: array, meta: array}
     */
    public function generate(array $input, int $tenantId, User $actor): array
    {
        $ctx = $this->resolveContext($input, $tenantId);

        // With no role information at all the model has nothing to work from and
        // would invent a generic quiz — refuse rather than return noise.
        if (blank($ctx['job_description']) && blank($ctx['designation']) && empty($ctx['skills'])) {
            throw new BusinessException(
                'Give the generator something to work from — pick a job posting, or set a designation or some skills.', 422
            );
        }

        $prompt = InterviewQuestionPromptBuilder::build($ctx);

        try {
            $raw = $this->ai->complete($prompt['user'], [
                'system'      => $prompt['system'],
                'max_tokens'  => (int) config('ai.defaults.max_tokens', 1800),
                'temperature' => (float) config('ai.defaults.temperature', 0.6),
            ]);
        } catch (AIException $e) {
            Log::channel('hr')->warning('AI interview question generation failed', [
                'tenant_id' => $tenantId, 'error' => $e->getMessage(),
            ]);
            throw new BusinessException('AI generation is currently unavailable: '.$e->getMessage(), 503);
        }

        $meta = [
            'provider'     => $this->ai->name(),
            'model'        => $this->ai->model(),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $actor->name,
            // The inputs are kept, not just the output: "why did it ask that?" is
            // answerable later only if we know what it was told.
            'inputs'       => array_filter([
                'designation'    => $ctx['designation'],
                'skills'         => $ctx['skills'],
                'experience_min' => $ctx['experience_min'],
                'experience_max' => $ctx['experience_max'],
                'types'          => $ctx['types'],
                'difficulty'     => $ctx['difficulty'],
                'count'          => $ctx['count'],
                'job_posting_id' => $input['job_posting_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
        ];

        return [
            'questions' => $this->parse($raw, $ctx, $meta),
            'meta'      => $meta,
        ];
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    /** Fill the prompt context, preferring an actual job posting when given one. */
    private function resolveContext(array $input, int $tenantId): array
    {
        $jd = $input['job_description'] ?? null;
        $designation = $input['designation'] ?? null;
        $skills = $input['skills'] ?? [];
        $expMin = $input['experience_min'] ?? null;
        $expMax = $input['experience_max'] ?? null;

        if (! empty($input['job_posting_id'])) {
            $job = HrJobPosting::where('tenant_id', $tenantId)->find($input['job_posting_id']);

            if (! $job) {
                throw new BusinessException('Job posting not found', 404);
            }

            $jd ??= $job->description ?: $job->requirements;
            $designation ??= $job->title;

            // A job posting carries no skills or experience of its own — both live
            // on the requisition it was raised from, which is the single place
            // they are captured.
            $mr = $job->manpowerRequest;
            $skills = $skills ?: ($mr?->required_skills ?: []);
            // Free-text like "3-5 years" is how experience is stored, so it is
            // read as a range rather than assumed to be a number.
            [$min, $max] = $this->parseExperience($mr?->experience_required);
            $expMin ??= $min;
            $expMax ??= $max;
        }

        return [
            'job_description' => $jd,
            'designation'     => $designation,
            'skills'          => SkillMatcher::clean(is_array($skills) ? $skills : explode(',', (string) $skills)),
            'experience_min'  => $expMin,
            'experience_max'  => $expMax,
            'count'           => $input['count'] ?? 8,
            'types'           => $input['types'] ?? [],
            'difficulty'      => $input['difficulty'] ?? null,
            'category'        => $input['category'] ?? null,
        ];
    }

    /** "3-5 years" → [3, 5]; "5+ years" → [5, null]. */
    private function parseExperience(?string $text): array
    {
        if (blank($text)) {
            return [null, null];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*[-–to]+\s*(\d+(?:\.\d+)?)/i', $text, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        if (preg_match('/(\d+(?:\.\d+)?)/', $text, $m)) {
            return [(float) $m[1], null];
        }

        return [null, null];
    }

    /**
     * The model's JSON into draft question rows.
     *
     * Everything is normalised to what the bank will accept, because a draft the
     * user edits and saves must not fail validation on a field they never saw.
     * An MCQ that came back with no correct option is downgraded to subjective
     * rather than dropped — the question itself is usually still worth asking.
     */
    private function parse(string $raw, array $ctx, array $meta): array
    {
        $decoded = json_decode($this->stripFence($raw), true);

        if (! is_array($decoded) || ! is_array($decoded['questions'] ?? null)) {
            Log::channel('hr')->warning('AI returned unparseable interview questions', [
                'snippet' => mb_substr($raw, 0, 300),
            ]);
            throw new BusinessException('The AI response could not be read as questions. Try generating again.', 502);
        }

        return collect($decoded['questions'])
            ->filter(fn ($q) => is_array($q) && ! blank($q['question_text'] ?? null))
            ->map(function ($q) use ($ctx, $meta) {
                $type = in_array($q['question_type'] ?? '', HrInterviewQuestion::TYPES, true)
                    ? $q['question_type'] : HrInterviewQuestion::SUBJECTIVE;

                $options = $type === HrInterviewQuestion::MCQ
                    ? $this->normaliseOptions($q['options'] ?? [])
                    : null;

                // An MCQ we cannot score is not an MCQ.
                if ($type === HrInterviewQuestion::MCQ && ($options === null || count($options) < 2
                    || collect($options)->every(fn ($o) => ! $o['is_correct']))) {
                    $type = HrInterviewQuestion::SUBJECTIVE;
                    $options = null;
                }

                return [
                    'question_text'   => trim((string) $q['question_text']),
                    'question_type'   => $type,
                    'difficulty'      => in_array($q['difficulty'] ?? '', HrInterviewQuestion::DIFFICULTIES, true)
                        ? $q['difficulty'] : ($ctx['difficulty'] ?: 'medium'),
                    'category'        => $q['category'] ?? $ctx['category'] ?? null,
                    'skills'          => SkillMatcher::clean((array) ($q['skills'] ?? $ctx['skills'])),
                    'tags'            => array_values(array_filter(array_map('trim', (array) ($q['tags'] ?? [])))),
                    'expected_answer' => $q['expected_answer'] ?? null,
                    'marks'           => (float) ($q['marks'] ?? 5),
                    'options'         => $options ?: [],
                    'experience_min'  => $ctx['experience_min'],
                    'experience_max'  => $ctx['experience_max'],
                    'is_active'       => true,
                    // Provenance travels with the draft so it survives into the
                    // bank when the reviewer saves it.
                    'source'          => 'ai',
                    'ai_meta'         => $meta,
                ];
            })->values()->all();
    }

    private function normaliseOptions($options): ?array
    {
        if (! is_array($options)) {
            return null;
        }

        return collect($options)
            ->map(fn ($o) => is_array($o)
                ? ['text' => trim((string) ($o['text'] ?? '')), 'is_correct' => (bool) ($o['is_correct'] ?? false)]
                : ['text' => trim((string) $o), 'is_correct' => false])
            ->filter(fn ($o) => $o['text'] !== '')
            ->values()->all();
    }

    /** Models often wrap JSON in a ```json fence despite being told not to. */
    private function stripFence(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);

        // Anything outside the outermost object is commentary the model added.
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        return ($start !== false && $end !== false && $end > $start)
            ? substr($raw, $start, $end - $start + 1)
            : $raw;
    }
}
