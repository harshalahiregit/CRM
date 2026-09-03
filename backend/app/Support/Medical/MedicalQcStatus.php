<?php

namespace App\Support\Medical;

/**
 * The quality-check verdict on a medical certificate.
 *
 * A certificate is not "medical clearance" the moment a doctor signs it — the
 * quality team has to accept it first. Three verdicts, and the difference
 * between the two negatives is the whole point:
 *
 *  - HOLD     — sent back. Fixable: the vendor or doctor answers and resubmits,
 *               and the back-and-forth counter advances. Not terminal.
 *  - REJECTED — terminal. The worker failed the medical (age limit, physically
 *               unfit); resubmitting the same certificate is not the remedy, a
 *               re-examination is.
 *
 * Shared by TPV and Purchase deliberately: the vocabulary of a verdict is the
 * same conversation on both sides, and duplicating it would let the two drift.
 */
final class MedicalQcStatus
{
    public const PENDING  = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const HOLD     = 'Hold';

    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED, self::HOLD];

    /** The verdicts a reviewer can hand down (PENDING is the arrival state). */
    public const DECISIONS = [self::APPROVED, self::REJECTED, self::HOLD];

    /** Verdicts that require a reason — refusing without one is not reviewable. */
    public const REASON_REQUIRED = [self::REJECTED, self::HOLD];

    public const LABELS = [
        self::PENDING  => 'Pending review',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
        self::HOLD     => 'On hold',
    ];

    /**
     * The shipped reason catalogue. A tenant can replace this list in the
     * medical settings group; these are the reasons the senior named plus the
     * document faults a reviewer actually meets.
     *
     * @return list<array{value:string,label:string,applies_to:list<string>}>
     */
    public static function defaultReasons(): array
    {
        return [
            ['value' => 'age_limit',           'label' => 'Outside the permitted age limit',        'applies_to' => [self::REJECTED]],
            ['value' => 'physically_unfit',    'label' => 'Physically unfit for the job',           'applies_to' => [self::REJECTED]],
            ['value' => 'failed_investigation', 'label' => 'Failed a required investigation',       'applies_to' => [self::REJECTED]],
            ['value' => 'certificate_expired', 'label' => 'Certificate already expired',            'applies_to' => [self::REJECTED, self::HOLD]],
            ['value' => 'illegible_document',  'label' => 'Document illegible or incomplete',       'applies_to' => [self::HOLD]],
            ['value' => 'license_unverifiable', 'label' => "Doctor's licence could not be verified", 'applies_to' => [self::HOLD]],
            ['value' => 'missing_tests',       'label' => 'Required tests missing from the report', 'applies_to' => [self::HOLD]],
            ['value' => 'identity_mismatch',   'label' => 'Worker identity does not match',         'applies_to' => [self::HOLD, self::REJECTED]],
            ['value' => 'other',               'label' => 'Other (see note)',                       'applies_to' => [self::HOLD, self::REJECTED]],
        ];
    }

    public static function label(?string $v): string
    {
        return self::LABELS[$v] ?? (string) $v;
    }

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }

    /** A held certificate can come back; a rejected one cannot. */
    public static function isResubmittable(?string $v): bool
    {
        return $v === self::HOLD;
    }

    /** Only an approved certificate counts as medical clearance. */
    public static function isCleared(?string $v): bool
    {
        return $v === self::APPROVED;
    }
}
