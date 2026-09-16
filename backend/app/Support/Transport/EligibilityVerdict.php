<?php

namespace App\Support\Transport;

/**
 * The shape both eligibility services return.
 *
 * Copied deliberately from TpvWorkAuthorizationService, which already answers the
 * structurally identical question ("may this worker do this work?") elsewhere in
 * this codebase:
 *
 *   { eligible, checks: [ {key, label, required, passed, detail} ], ... }
 *
 * Two properties earn it:
 *
 *   RULES ARE DATA, NOT BRANCHES. Each check carries its own `required` flag,
 *   read from policy. CMP §20 requires the blocking rule to be configurable, and
 *   a flag on a row makes that a settings change rather than a deploy.
 *
 *   A REFUSAL EXPLAINS ITSELF. QA-003 requires allocation to be "blocked with an
 *   actionable message", and BRWM §70 spells out the tone: "Action: Upload valid
 *   licence or assign another eligible driver." A boolean cannot say that; a list
 *   of failed checks with details can.
 *
 * `eligible` is true when every REQUIRED check passed. Advisory checks may fail
 * without blocking — that is what makes FLEET §13's expiry warnings useful
 * rather than obstructive.
 */
final class EligibilityVerdict
{
    /** One check result. */
    public static function check(string $key, string $label, bool $required, bool $passed, string $detail): array
    {
        return compact('key', 'label', 'required', 'passed', 'detail');
    }

    /**
     * Fold checks into a verdict.
     *
     * @param  array<int,array<string,mixed>>  $checks
     * @param  array<string,mixed>             $subject
     */
    public static function make(array $subject, array $checks, array $context = []): array
    {
        $blocking = array_values(array_filter($checks, fn ($c) => $c['required'] && ! $c['passed']));
        $warnings = array_values(array_filter($checks, fn ($c) => ! $c['required'] && ! $c['passed']));

        return array_merge([
            'subject'  => $subject,
            'eligible' => $blocking === [],
            'checks'   => $checks,
            // Pre-extracted so a UI and a log line do not each re-derive them.
            'blockers' => array_map(fn ($c) => $c['detail'], $blocking),
            'warnings' => array_map(fn ($c) => $c['detail'], $warnings),
        ], $context);
    }
}
