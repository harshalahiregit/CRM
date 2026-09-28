<?php

namespace App\Support\Transport;

/**
 * The outcome of ONE pre-trip check item — STOS-FLEET §88, verbatim.
 *
 * FLEET §88 grades an inspection:
 *
 *   PASS | PASS WITH WARNING | FAIL | CRITICAL FAIL
 *
 * and §89 states the consequence of the last one: "Critical failure: Vehicle
 * unavailable for dispatch." That is the only place in the package where a check
 * outcome is tied to a dispatch consequence by the same document, which is why
 * this four-value list is used for items rather than CMP §158's or OPS §29's.
 *
 * ── WHY FAIL AND CRITICAL_FAIL ARE BOTH HERE ──────────────────────────────
 * BRW-052 is the rule that needs the distinction, and it is worth quoting whole
 * because it is the heart of this ticket:
 *
 *   "Critical failure: Dispatch blocked.
 *    Non-critical warning: Continue with warning/approval depending on policy."
 *
 * So a failed check does not by itself block. Whether it blocks depends on
 * whether the check is critical, and THAT is policy, not code — S6-004:
 * "Mandatory checks configurable; failed items block dispatch when policy says
 * so." The same discipline the eligibility services already use for their
 * `required` flags, and for the same reason: moving a check from advisory to
 * blocking must be a settings row, not a deploy.
 *
 * Which means the mapping is mechanical, not a judgement made at grading time:
 *   critical check + failed  → CRITICAL_FAIL → blocks (OPS §30, BRW-046)
 *   advisory check + failed  → FAIL          → warns, does not block
 *   passed with a caveat     → PASS_WARNING  → warns, does not block
 *
 * PASS_WARNING is what a check returns when it succeeds but has something to say
 * — a document valid today and expiring inside the tenant's configured window,
 * which is precisely the case UX §36 exists for ("warning is not a block").
 *
 * ── PENDING IS NOT IN §88 ─────────────────────────────────────────────────
 * A fifth value, PENDING, is added and flagged. §88 grades a COMPLETED
 * inspection and has no vocabulary for one not yet done, but a generated
 * checklist must be able to say "this row exists and nobody has answered it" —
 * that is the whole of OPS §29's IN_PROGRESS, which the package does require.
 * Storing null instead would push the same meaning into an untyped absence and
 * make every consumer handle it separately.
 *
 * It is kept out of RESULTS_88 so the boundary between the document's four
 * values and this module's fifth stays visible and testable.
 * FLAGGED: no source defines an ungraded state. Recorded as D-16.
 */
final class PretripResult
{
    /** Generated, awaiting a result. NOT a FLEET §88 value — see the docblock. */
    public const PENDING = 'pending';

    /** FLEET §88 "PASS". */
    public const PASS = 'pass';

    /** FLEET §88 "PASS WITH WARNING". Passes; carries a caveat. UX §36. */
    public const PASS_WARNING = 'pass_warning';

    /** FLEET §88 "FAIL". A non-critical failure — BRW-052 warns, does not block. */
    public const FAIL = 'fail';

    /** FLEET §88 "CRITICAL FAIL". BRW-052 / §89 / OPS §30: dispatch blocked. */
    public const CRITICAL_FAIL = 'critical_fail';

    /** FLEET §88's four values, in the document's order. */
    public const RESULTS_88 = [
        self::PASS, self::PASS_WARNING, self::FAIL, self::CRITICAL_FAIL,
    ];

    public const ALL = [
        self::PENDING, self::PASS, self::PASS_WARNING, self::FAIL, self::CRITICAL_FAIL,
    ];

    /** A result a person or a rule may record. PENDING is a generated state only. */
    public const GRADED = self::RESULTS_88;

    /** Results that satisfy their item. */
    public const SATISFIED = [self::PASS, self::PASS_WARNING];

    /** Results that leave a caveat worth surfacing but do not block. UX §36. */
    public const WARNING = [self::PASS_WARNING, self::FAIL];

    /** The only result that blocks dispatch. BRW-052, FLEET §89, OPS §30. */
    public const BLOCKING = [self::CRITICAL_FAIL];

    public const LABELS = [
        self::PENDING       => 'Pending',
        self::PASS          => 'Pass',
        self::PASS_WARNING  => 'Pass with warning',
        self::FAIL          => 'Fail',
        self::CRITICAL_FAIL => 'Critical fail',
    ];

    public static function isValid(string $result): bool
    {
        return in_array($result, self::ALL, true);
    }

    public static function label(string $result): string
    {
        return self::LABELS[$result] ?? $result;
    }

    public static function satisfied(string $result): bool
    {
        return in_array($result, self::SATISFIED, true);
    }

    public static function blocks(string $result): bool
    {
        return in_array($result, self::BLOCKING, true);
    }

    /**
     * BRW-052, as one expression: a failure blocks only when the check is
     * critical. Criticality comes from policy, never from the grader.
     */
    public static function forFailure(bool $critical): string
    {
        return $critical ? self::CRITICAL_FAIL : self::FAIL;
    }
}
