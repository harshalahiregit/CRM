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
    /**
     * One check result.
     *
     * `$owner` is the desk that can clear a failure — D-150. Optional because
     * not every rule has one: a capacity mismatch is nobody's to clear, it is
     * the wrong truck for the load. Where a rule DOES belong to somebody,
     * naming them is the difference between "blocked" and a dispatcher knowing
     * who to ring.
     */
    public static function check(
        string $key,
        string $label,
        bool $required,
        bool $passed,
        string $detail,
        ?string $owner = null,
    ): array {
        return compact('key', 'label', 'required', 'passed', 'detail', 'owner');
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
            // ── ONE SHAPE, ALWAYS — D-150 ────────────────────────────────
            // These were plain strings, while Fleet's driver WARNINGS came
            // through as {code, why, owner}. The same response carried two
            // shapes for one idea, and the component that had to read both got
            // it wrong the first time it was touched: D-147's `reason()`
            // rendered the object correctly and the string as EMPTY, so an
            // ineligible driver showed with no reason at all.
            //
            // A component absorbing two shapes is how a third appears. The
            // service emits one, and EligibilityVerdictShapeTest holds it.
            'blockers' => array_map(fn ($c) => self::reason($c), $blocking),
            'warnings' => array_map(fn ($c) => self::reason($c), $warnings),
        ], $context);
    }

    /**
     * A check's failure, as the one shape everything downstream reads.
     *
     * Deliberately the same keys Fleet's own blockers use — `code`, `why`,
     * `owner` — so a verdict that passes Fleet's reasons through and one that
     * builds its own are indistinguishable to a reader.
     *
     * @param  array<string,mixed>  $check
     * @return array{code:string,why:string,owner:string|null}
     */
    private static function reason(array $check): array
    {
        return [
            'code'  => $check['key'],
            'why'   => $check['detail'],
            'owner' => $check['owner'] ?? null,
        ];
    }
}
