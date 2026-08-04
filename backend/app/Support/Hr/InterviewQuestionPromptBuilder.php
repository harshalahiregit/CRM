<?php

namespace App\Support\Hr;

use App\Models\Hr\HrInterviewQuestion;

/**
 * #10 — builds the LLM prompt for interview question generation.
 *
 * Modelled on JobDescriptionPromptBuilder and role-AGNOSTIC for the same reason:
 * the inputs (job description, designation, skills, experience) describe any
 * role, so adding a new one needs no code change here.
 *
 * The output contract is strict JSON rather than prose. A generated question has
 * to land in typed columns — difficulty, skills, options with correct flags — and
 * parsing those back out of formatted text would be guesswork.
 */
class InterviewQuestionPromptBuilder
{
    /**
     * @param  array  $ctx  ['job_description', 'designation', 'skills' => array,
     *                       'experience_min', 'experience_max', 'count',
     *                       'types' => array, 'difficulty', 'category']
     * @return array{system:string, user:string}
     */
    public static function build(array $ctx): array
    {
        $types = array_values(array_filter(
            (array) ($ctx['types'] ?? []),
            fn ($t) => in_array($t, HrInterviewQuestion::TYPES, true)
        )) ?: [HrInterviewQuestion::TECHNICAL, HrInterviewQuestion::BEHAVIOURAL];

        $count = max(1, min(25, (int) ($ctx['count'] ?? 8)));

        $system = implode(' ', [
            'You are an experienced technical interviewer and talent-assessment specialist.',
            'You write interview questions that reveal how a candidate actually thinks, not questions answered by recall alone.',
            'Return STRICT JSON ONLY — a single object, no Markdown fence, no commentary before or after.',
            'Base every question on the role information given. Do not invent technologies the role does not mention.',
            'Never produce a question that probes age, gender, marital status, religion, caste, pregnancy, or health —',
            'those are unlawful to ask in an interview and must never appear.',
        ]);

        $schema = <<<'JSON'
{
  "questions": [
    {
      "question_text": "string",
      "question_type": "mcq|subjective|coding|practical|behavioural|technical|hr",
      "difficulty": "easy|medium|hard|expert",
      "category": "short topic label, e.g. 'System Design'",
      "skills": ["skill", "..."],
      "tags": ["tag", "..."],
      "expected_answer": "what a strong answer contains — required for every non-mcq question",
      "marks": 5,
      "options": [{"text": "string", "is_correct": true}]
    }
  ]
}
JSON;

        $lines = array_filter([
            'Generate exactly '.$count.' interview questions.',
            'Allowed question types (use only these, mixed sensibly): '.implode(', ', $types).'.',
            ! empty($ctx['difficulty']) ? 'Target difficulty: '.$ctx['difficulty'].'.' : null,
            ! empty($ctx['designation']) ? 'Role / designation: '.$ctx['designation'].'.' : null,
            ! empty($ctx['skills']) ? 'Required skills: '.implode(', ', (array) $ctx['skills']).'.' : null,
            self::experienceLine($ctx),
            ! empty($ctx['category']) ? 'Focus area: '.$ctx['category'].'.' : null,
            ! empty($ctx['job_description'])
                ? "Job description:\n\"\"\"\n".mb_substr((string) $ctx['job_description'], 0, 4000)."\n\"\"\""
                : null,
        ]);

        $rules = [
            'Rules:',
            '- "options" is REQUIRED for mcq questions and must be omitted for every other type.',
            '- An mcq must have 4 options with at least one is_correct:true; mark MORE than one when the question genuinely has several correct answers.',
            '- "expected_answer" is REQUIRED for every non-mcq question — it is what lets two interviewers score alike.',
            '- "skills" must use the exact skill names given above where they apply.',
            '- Vary difficulty and type across the set rather than repeating one shape.',
            '- Return the JSON object and nothing else.',
        ];

        return [
            'system' => $system,
            'user'   => implode("\n", $lines)."\n\n".implode("\n", $rules)
                ."\n\nReturn JSON in exactly this shape:\n".$schema,
        ];
    }

    private static function experienceLine(array $ctx): ?string
    {
        $min = $ctx['experience_min'] ?? null;
        $max = $ctx['experience_max'] ?? null;

        if ($min === null && $max === null) {
            return null;
        }
        if ($min !== null && $max !== null) {
            return "Candidate experience: {$min}–{$max} years.";
        }

        return $min !== null ? "Candidate experience: {$min}+ years." : "Candidate experience: up to {$max} years.";
    }
}
