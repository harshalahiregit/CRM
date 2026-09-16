<?php

namespace App\Support\Transport;

/**
 * Where an exception stands against its SLA — STOS-OPS §104, verbatim.
 *
 * §104 "SLA MONITORING" says, in full: "Sangoe must show: On Track / At Risk /
 * Overdue." Three values, and this is the whole of the document's requirement.
 *
 * ── DERIVED, NEVER STORED ────────────────────────────────────────────────
 * There is no sla_state column, for the same reason PretripReadiness has none:
 * a stored value is wrong the minute after it is written unless something
 * sweeps, and no P0 ticket owns a sweep. "Overdue" is a fact about the clock,
 * not about the row — so it is computed from due_at every time it is asked for,
 * and it is right at the instant it is read.
 *
 * That also means SLA breach needs no scheduler to be VISIBLE. §105 wants a
 * warning notification before breach and an escalation at breach; those need
 * SNG-TRN-021 (P1) and are deferred. Showing the state does not.
 *
 * ── WALL CLOCK, BY RULING ────────────────────────────────────────────────
 * Owner's Q5 ruling, 2026-09-10: elapsed wall-clock only. No working hours, no
 * holidays, no pause-and-resume. A business calendar is D-34 — nothing in the
 * package defines one, and an SLA that silently skipped a Sunday would be
 * unauditable against a document that never mentions Sundays.
 *
 * ── AT_RISK NEEDS A THRESHOLD THE DOCUMENT DOES NOT GIVE ─────────────────
 * §104 names the state but never says when On Track becomes At Risk. TRP-P0-011
 * offers "Critical alert SLA e.g. 15/30/60 min configurable" — durations, not a
 * warning point. So the threshold is a tenant policy with a stated default
 * rather than a guessed constant, and CMP §10's NO ASSUMPTION PRINCIPLE is why
 * it is a fraction of the SLA window rather than a fixed number of minutes: a
 * fixed 15 minutes is meaningless against a 15-minute SLA and generous against
 * a 24-hour one.
 */
final class ExceptionSlaState
{
    /** §104: within the SLA window, and not near its end. */
    public const ON_TRACK = 'on_track';

    /** §104: still within the window, but past the warning threshold. */
    public const AT_RISK = 'at_risk';

    /** §104: the due time has passed and the exception is not terminal. */
    public const OVERDUE = 'overdue';

    /**
     * Not one of §104's three, and deliberately distinct from them.
     *
     * An exception that has been resolved has no live SLA — reporting it as
     * On Track would imply a clock still running, and Overdue would punish a
     * closed item forever. §104 describes trips in flight; this is what the
     * same question answers once the clock has stopped.
     */
    public const STOPPED = 'stopped';

    /**
     * No due time to measure against.
     *
     * Reachable: policy may set a severity's SLA to zero/absent, and CMP §10
     * forbids inventing one. An exception with no SLA is a real state, and
     * saying so is better than defaulting it to On Track and implying a
     * deadline nobody set.
     */
    public const NONE = 'none';

    /** §104's three, then the two this system needs to be honest. */
    public const ALL = [self::ON_TRACK, self::AT_RISK, self::OVERDUE, self::STOPPED, self::NONE];

    /** The three §104 actually names. Asserted by test. */
    public const OPS_104 = [self::ON_TRACK, self::AT_RISK, self::OVERDUE];

    /** States that mean a live clock is running against a due time. */
    public const LIVE = [self::ON_TRACK, self::AT_RISK, self::OVERDUE];

    /** The only one that is a breach. §105's escalation point. */
    public const BREACHED = [self::OVERDUE];

    public const LABELS = [
        self::ON_TRACK => 'On track',
        self::AT_RISK  => 'At risk',
        self::OVERDUE  => 'Overdue',
        self::STOPPED  => 'Clock stopped',
        self::NONE     => 'No SLA set',
    ];

    public static function isValid(string $state): bool
    {
        return in_array($state, self::ALL, true);
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    /** Has the SLA been breached? §105's "at breach" condition. */
    public static function isBreached(string $state): bool
    {
        return in_array($state, self::BREACHED, true);
    }

    /** Is a clock still running? False once resolved, or when no SLA is set. */
    public static function isLive(string $state): bool
    {
        return in_array($state, self::LIVE, true);
    }
}
