<?php

namespace App\Support\Hr;

use App\Models\Hr\HrManpowerRequest;

/**
 * Builds the LLM prompt for an AI Job Description from an approved Manpower
 * Request. Deliberately role-AGNOSTIC — the same builder produces a good JD for
 * a PHP Developer, React Developer, HR Executive, Sales Manager, QA, DevOps, …
 * purely from the requisition fields, so new roles need NO controller/service
 * changes. Extend by adding role-specific hints in `roleHints()` only.
 */
class JobDescriptionPromptBuilder
{
    /** The 11 required JD sections, in order. */
    public const SECTIONS = [
        'About Company', 'About Role', 'Responsibilities', 'Required Skills',
        'Preferred Skills', 'Qualification', 'Experience', 'Benefits',
        'Work Mode', 'Hiring Process', 'Equal Opportunity Statement',
    ];

    /** Allowed AI option values — the UI + validation reference this catalog. */
    public const OPTIONS = [
        'tone'            => ['Professional', 'Corporate', 'Startup', 'Technical', 'Executive'],
        'industry'        => ['IT', 'Healthcare', 'Finance', 'Manufacturing', 'Retail', 'Telecom', 'Education'],
        'experience_level' => ['Intern', 'Junior', 'Mid', 'Senior', 'Lead'],
        'employment_type' => ['Full Time', 'Contract', 'Internship', 'Remote', 'Hybrid', 'Onsite'],
    ];

    /**
     * @param  array  $options  Optional AI directives: tone, industry,
     *                          experience_level, employment_type (from OPTIONS).
     * @return array{system:string, user:string}
     */
    public static function buildPrompt(HrManpowerRequest $mr, ?string $companyName = null, array $options = []): array
    {
        $company = $companyName ?: 'the company';

        $system = implode(' ', [
            'You are an expert HR copywriter and talent-acquisition specialist.',
            'Write a professional, inclusive, and compelling Job Description from the structured requisition provided.',
            'Return CLEAN PLAIN TEXT ONLY — no Markdown symbols (no #, *, backticks), no HTML tags.',
            'Use Title-Case section headings on their own line, a blank line between sections, and "•" bullet points.',
            'Base the content strictly on the facts given; do not invent salary, benefits, or requirements that contradict the input.',
            'Where a section has little data, write a tasteful, generic-but-relevant paragraph rather than a placeholder.',
        ]);

        $facts = self::facts($mr, $company);

        $sections = implode("\n", array_map(fn ($s, $i) => ($i + 1).'. '.$s, self::SECTIONS, array_keys(self::SECTIONS)));

        $existing = trim((string) $mr->job_description);
        $context = $existing !== ''
            ? "\n\nThe hiring team already drafted these notes — USE THEM AS ADDITIONAL CONTEXT and improve on them, do NOT ignore or contradict them:\n\"\"\"\n{$existing}\n\"\"\""
            : '';

        $directives = self::directives($options);

        // Improvement mode — rewrite an existing (low-ATS) JD, optimising for ATS/
        // SEO/skills/etc. Reuses the same prompt; just swaps the context + goals.
        if (! empty($options['improve'])) {
            $current = trim((string) ($options['current_jd'] ?? '')) ?: $existing;
            if ($current !== '') {
                $context = "\n\nThis is an ATS-IMPROVEMENT rewrite. Here is the CURRENT Job Description that scored poorly — rewrite and OPTIMISE it, keeping every correct fact from the requisition and improving everything else:\n\"\"\"\n{$current}\n\"\"\"";
            }
            $directives .= self::improveDirectives();
        }

        $user = <<<TXT
Create a Job Description for {$company} using ONLY the requisition details below.

REQUISITION DETAILS
{$facts}
{$context}

Produce the Job Description with exactly these sections, in this order:
{$sections}
{$directives}
Formatting rules:
- Plain text only. Section heading on its own line, then the content.
- Use "•" for bullet lists (Responsibilities, Required Skills, Preferred Skills, Benefits).
- Keep it concise and professional; 350–600 words total.
- The "Hiring Process" section should outline the interview stages briefly.
- End with a short, standard Equal Opportunity Statement.
TXT;

        return ['system' => $system, 'user' => $user];
    }

    /**
     * Turn the optional AI controls into prompt directives. Values are validated
     * against self::OPTIONS so nothing arbitrary reaches the model. Returns an
     * empty string when no (valid) options are supplied.
     */
    private static function directives(array $options): string
    {
        $lines = [];
        $tone     = self::pick($options, 'tone');
        $industry = self::pick($options, 'industry');
        $level    = self::pick($options, 'experience_level');
        $type     = self::pick($options, 'employment_type');

        if ($tone)     { $lines[] = "- Write in a {$tone} tone throughout."; }
        if ($industry) { $lines[] = "- Tailor the language, examples and terminology to the {$industry} industry."; }
        if ($level)    { $lines[] = "- Pitch the role, responsibilities and expectations at a {$level}-level candidate."; }
        if ($type)     { $lines[] = "- Frame this clearly as a {$type} position (working arrangement and commitment)."; }

        return $lines ? "\nAdditional style directives (follow these):\n".implode("\n", $lines)."\n" : '';
    }

    /** ATS-optimisation goals appended to the prompt in improvement mode. */
    private static function improveDirectives(): string
    {
        return "\nOptimisation goals (ATS-improvement rewrite — maximise ALL of these):\n"
            ."- ATS: use standard, parseable section headings and a clear, consistent structure.\n"
            ."- SEO & keyword density: naturally weave in role-relevant keywords and every required skill by name.\n"
            ."- Skills: explicitly list all required and preferred skills.\n"
            ."- Responsibilities: give 5–8 concrete, action-oriented bullet points.\n"
            ."- Qualifications & Experience: state clear education, certifications and experience expectations.\n"
            ."- Inclusive language: remove any biased, gendered or ageist wording.\n"
            ."- Length: produce a complete 400–650 word description (never too short).\n"
            ."- Formatting: clean headings and '•' bullets, well organised and scannable.\n";
    }

    /** Return an option value only if it is in the allowed catalog. */
    private static function pick(array $options, string $key): ?string
    {
        $value = trim((string) ($options[$key] ?? ''));

        return ($value !== '' && in_array($value, self::OPTIONS[$key] ?? [], true)) ? $value : null;
    }

    /** Compile the requisition fields into a labelled, empty-skipping fact block. */
    private static function facts(HrManpowerRequest $mr, string $company): string
    {
        $salary = self::salary($mr);
        $rows = [
            'Company'             => $company,
            'Business Unit'       => $mr->business_unit,
            'Department'          => $mr->department,
            'Project'             => $mr->project,
            'Location'            => $mr->location,
            'Job Title'           => $mr->position_title,
            'Employee Level'      => $mr->employee_level,
            'Employment Type'     => $mr->job_type,
            'Work Mode'           => $mr->work_mode,
            'Shift'               => $mr->shift,
            'Experience Required' => $mr->experience_required,
            'Salary Range'        => $salary,
            'Required Skills'     => self::listOf($mr->required_skills),
            'Preferred Skills'    => self::listOf($mr->preferred_skills),
            'Education'           => $mr->education,
            'Certifications'      => self::listOf($mr->certifications),
            'Hiring Reason'       => $mr->hiring_reason,
            'Priority'            => $mr->priority,
            'Criticality'         => $mr->criticality,
            'Target Joining Date' => optional($mr->target_joining_date)->toDateString(),
            'Additional Remarks'  => $mr->justification,
        ];

        $lines = [];
        foreach ($rows as $label => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value !== null && $value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    private static function salary(HrManpowerRequest $mr): ?string
    {
        $min = $mr->salary_min ? (float) $mr->salary_min : null;
        $max = $mr->salary_max ? (float) $mr->salary_max : null;
        if (! $min && ! $max) {
            return null;
        }
        $fmt = fn ($n) => '₹'.number_format($n);

        return $min && $max ? $fmt($min).' – '.$fmt($max)
            : ($min ? 'From '.$fmt($min) : 'Up to '.$fmt($max));
    }

    private static function listOf($value): ?string
    {
        if (is_array($value)) {
            $value = array_filter(array_map('trim', $value));

            return $value ? implode(', ', $value) : null;
        }

        return $value ? (string) $value : null;
    }
}
