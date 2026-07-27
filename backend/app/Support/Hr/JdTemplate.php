<?php

namespace App\Support\Hr;

use App\Models\Hr\HrManpowerRequest;

/**
 * Standard Job Description format (SPK-1).
 *
 * Builds the JD from the approved Manpower Request so HR never retypes anything
 * the requisition already holds. Sections the MR has no field for (Benefits,
 * Working Hours, responsibility bullets) are emitted as clearly-marked prompts
 * that HR edits once in the existing JD editor — the JD body is free text, so
 * that needs no new columns.
 *
 * Single source of truth: the PHP side builds this for both the "Convert to JD"
 * flow and the "Post Job" prefill, so the two can never drift apart.
 */
class JdTemplate
{
    /** Marker for a section the requisition cannot supply. */
    private const TODO = '[To be completed by HR]';

    public static function build(HrManpowerRequest $mr, ?string $companyName = null): string
    {
        $company = $companyName ?: 'our organisation';
        $skills  = self::listOf($mr->required_skills);
        $pref    = self::listOf($mr->preferred_skills);

        $s = [];

        $s[] = 'Job Title: '.($mr->position_title ?: self::TODO);
        $s[] = 'Department: '.($mr->department ?: self::TODO);
        $s[] = 'Location: '.($mr->location ?: self::TODO);
        $s[] = 'Job Type: '.($mr->job_type ?: self::TODO);
        $s[] = 'Salary: '.self::salary($mr);
        $s[] = '';

        $s[] = 'About the Company';
        $s[] = self::about($company, $mr);
        $s[] = '';

        $s[] = 'Job Summary';
        $s[] = self::summary($mr, $company);
        $s[] = '';

        $s[] = 'Key Responsibilities';
        foreach (self::responsibilities($mr) as $line) {
            $s[] = '- '.$line;
        }
        $s[] = '';

        $s[] = 'Required Skills & Qualifications';
        $s[] = '- Qualification: '.($mr->education ?: self::TODO);
        $s[] = '- Skills: '.($skills ? implode(', ', $skills) : self::TODO);
        $s[] = '- Experience: '.($mr->experience_required ?: self::TODO);
        $s[] = '';

        $s[] = 'Preferred Qualifications';
        if ($pref) {
            foreach ($pref as $p) {
                $s[] = '- '.$p;
            }
        } else {
            $s[] = '- '.self::TODO;
        }
        $s[] = '';

        $s[] = 'Benefits';
        $s[] = '- '.self::TODO.' (e.g. health cover, paid leave, learning budget)';
        $s[] = '';

        $s[] = 'Working Hours';
        $s[] = self::workingHours($mr);
        $s[] = '';

        $s[] = 'How to Apply';
        $s[] = 'Apply through the careers portal link on this posting. Shortlisted candidates will be contacted by the '.($mr->department ?: 'hiring').' team.';
        $s[] = '';

        $s[] = 'Application Deadline';
        $s[] = $mr->target_joining_date
            ? 'Applications close ahead of the target joining date: '.self::date($mr->target_joining_date).'.'
            : self::TODO;

        return implode("\n", $s);
    }

    /** Skills columns are cast to array but may hold a comma string on old rows. */
    private static function listOf($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }
        if (is_string($value) && trim($value) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    private static function salary(HrManpowerRequest $mr): string
    {
        $min = $mr->salary_min;
        $max = $mr->salary_max;
        if (! $min && ! $max) {
            return 'As per industry standards and experience';
        }
        if ($min && $max) {
            return self::money($min).' – '.self::money($max).' per annum';
        }

        return self::money($min ?: $max).' per annum';
    }

    private static function money($v): string
    {
        $n = (float) $v;

        return $n >= 100000
            ? '₹'.rtrim(rtrim(number_format($n / 100000, 2, '.', ''), '0'), '.').' LPA'
            : '₹'.number_format($n, 0);
    }

    private static function about(string $company, HrManpowerRequest $mr): string
    {
        $bits = [$company.' is hiring for its '.($mr->department ?: 'growing').' team'];
        if ($mr->business_unit) {
            $bits[] = 'within the '.$mr->business_unit.' business unit';
        }
        if ($mr->project) {
            $bits[] = 'on the '.$mr->project.' project';
        }

        return implode(' ', $bits).'.';
    }

    private static function summary(HrManpowerRequest $mr, string $company): string
    {
        // The requisition's own description/justification is the closest thing to
        // a summary — reuse it verbatim rather than asking HR to write it twice.
        $text = trim((string) ($mr->job_description ?: $mr->justification));
        if ($text !== '') {
            return $text;
        }

        $posts = (int) ($mr->number_of_posts ?: 1);

        return $company.' is looking for '.($posts > 1 ? $posts.' ' : 'a ').
            ($mr->position_title ?: 'professional').($posts > 1 ? 's' : '').
            ($mr->location ? ' based in '.$mr->location : '').'.';
    }

    /**
     * The MR has no responsibilities field. If its description is already written
     * as bullets, reuse those lines; otherwise emit a prompt rather than inventing
     * duties for a role we know nothing about.
     */
    private static function responsibilities(HrManpowerRequest $mr): array
    {
        $lines = preg_split('/\r?\n/', (string) $mr->job_description) ?: [];
        $bullets = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l !== '' && preg_match('/^[-*•]\s*/', $l)) {
                $bullets[] = preg_replace('/^[-*•]\s*/', '', $l);
            }
        }

        return $bullets ?: [self::TODO.' — list the day-to-day duties for this role'];
    }

    private static function workingHours(HrManpowerRequest $mr): string
    {
        return match ($mr->job_type) {
            'Part-time'  => 'Part-time schedule, to be agreed with the reporting manager.',
            'Internship' => 'Full-time hours for the duration of the internship.',
            'Contract'   => 'Full-time hours for the duration of the contract.',
            'Remote'     => 'Standard business hours, worked remotely.',
            default      => 'Monday to Friday, standard business hours.',
        };
    }

    private static function date($d): string
    {
        try {
            return \Carbon\Carbon::parse($d)->format('d M Y');
        } catch (\Throwable) {
            return (string) $d;
        }
    }
}
