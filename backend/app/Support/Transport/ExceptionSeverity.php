<?php

namespace App\Support\Transport;

/**
 * How bad an exception is — Step 11 ENUM-003, verbatim.
 *
 *   ENUM-003  exception_severity  low|medium|high|critical
 *   FLD-014   severity  VARCHAR(20)  NOT NULL  DEFAULT 'medium'  INDEX
 *   CTR-011   API-007 body, ENUM, REQUIRED, low/medium/high/critical
 *
 * Four values, agreed by every document that mentions them — the only enum in
 * this ticket without a conflict. OPS §89 supplies the definitions below, and
 * they are quoted rather than paraphrased because severity is what drives the
 * SLA, and a team that disagrees about "high" will disagree about its due time.
 *
 * ── SEVERITY IS ENTERED, NOT DERIVED ─────────────────────────────────────
 * CTR-011 makes it a required request field, so a person chooses it. Nothing
 * infers it: the three rules that would (BR-P0-008 fuel, BR-P0-009 toll,
 * BR-P0-010 route deviation) all need tickets that are not built, and guessing
 * a severity would be worse than asking for one.
 *
 * ── THE DEFAULT IS THE DOCUMENT'S, NOT A CHOICE ──────────────────────────
 * FLD-014 says DEFAULT 'medium'. That is a column default for rows that somehow
 * arrive without one; the API still requires the field, per CTR-011.
 */
final class ExceptionSeverity
{
    /** OPS §89: "No significant immediate impact." */
    public const LOW = 'low';

    /** OPS §89: "Operational attention required." */
    public const MEDIUM = 'medium';

    /** OPS §89: "Customer/financial/service impact." */
    public const HIGH = 'high';

    /** OPS §89: "Safety, major financial, customer or compliance risk." */
    public const CRITICAL = 'critical';

    /** ENUM-003's order, which is also least-to-most severe. */
    public const ALL = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    /** FLD-014's stated default. */
    public const DEFAULT = self::MEDIUM;

    /** OPS §89's definitions, kept next to the values they define. */
    public const DEFINITIONS = [
        self::LOW      => 'No significant immediate impact.',
        self::MEDIUM   => 'Operational attention required.',
        self::HIGH     => 'Customer/financial/service impact.',
        self::CRITICAL => 'Safety, major financial, customer or compliance risk.',
    ];

    public const LABELS = [
        self::LOW      => 'Low',
        self::MEDIUM   => 'Medium',
        self::HIGH     => 'High',
        self::CRITICAL => 'Critical',
    ];

    /**
     * Rank, for ordering a queue and for comparing two severities.
     *
     * Deliberately not the array index: an index changes if the list is ever
     * reordered for display, and a comparison that silently changes meaning is
     * the kind of defect nobody finds.
     */
    public const RANK = [
        self::LOW      => 1,
        self::MEDIUM   => 2,
        self::HIGH     => 3,
        self::CRITICAL => 4,
    ];

    /**
     * BR-P0-011 applies to CRITICAL exceptions only, verbatim: "No critical
     * exception can be marked resolved without resolution evidence."
     *
     * Named here rather than written as a comparison at the call site, so the
     * one place the rule's reach is decided is the one place it is stated.
     */
    public const REQUIRES_RESOLUTION_EVIDENCE = [self::CRITICAL];

    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::ALL, true);
    }

    public static function label(string $severity): string
    {
        return self::LABELS[$severity] ?? $severity;
    }

    public static function rank(string $severity): int
    {
        return self::RANK[$severity] ?? 0;
    }

    /** BR-P0-011's reach, as a question. */
    public static function requiresResolutionEvidence(string $severity): bool
    {
        return in_array($severity, self::REQUIRES_RESOLUTION_EVIDENCE, true);
    }

    public static function atLeast(string $severity, string $floor): bool
    {
        return self::rank($severity) >= self::rank($floor);
    }
}
