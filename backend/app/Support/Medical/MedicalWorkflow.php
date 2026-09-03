<?php

namespace App\Support\Medical;

/**
 * The vocabulary of the medical workflow that is not the verdict itself: where
 * a certificate came from, who is speaking on the timeline, and what each
 * timeline entry records.
 */
final class MedicalWorkflow
{
    /* ── Origin — how the certificate reached the register ──────────────── */

    /** A doctor filled the examination form in the doctor portal. */
    public const ORIGIN_DOCTOR_PORTAL = 'doctor_portal';
    /** A vendor uploaded one external doctor's certificate. */
    public const ORIGIN_VENDOR_UPLOAD = 'vendor_upload';
    /** A sheet of external certificates was imported in one go. */
    public const ORIGIN_BULK_UPLOAD = 'bulk_upload';
    /** Keyed in by an admin through the worker wizard / workforce screen. */
    public const ORIGIN_ADMIN = 'admin';

    public const ORIGINS = [
        self::ORIGIN_DOCTOR_PORTAL, self::ORIGIN_VENDOR_UPLOAD,
        self::ORIGIN_BULK_UPLOAD, self::ORIGIN_ADMIN,
    ];

    public const ORIGIN_LABELS = [
        self::ORIGIN_DOCTOR_PORTAL => 'Internal (doctor portal)',
        self::ORIGIN_VENDOR_UPLOAD => 'External (vendor upload)',
        self::ORIGIN_BULK_UPLOAD   => 'External (bulk upload)',
        self::ORIGIN_ADMIN         => 'Recorded by admin',
    ];

    /** Origins that carry a third-party certificate rather than our own exam. */
    public const EXTERNAL_ORIGINS = [self::ORIGIN_VENDOR_UPLOAD, self::ORIGIN_BULK_UPLOAD];

    /* ── Who is speaking on the timeline ────────────────────────────────── */

    public const SIDE_DOCTOR  = 'doctor';
    public const SIDE_VENDOR  = 'vendor';
    public const SIDE_QUALITY = 'quality';
    public const SIDE_ADMIN   = 'admin';
    public const SIDE_SYSTEM  = 'system';

    public const SIDES = [
        self::SIDE_DOCTOR, self::SIDE_VENDOR, self::SIDE_QUALITY,
        self::SIDE_ADMIN, self::SIDE_SYSTEM,
    ];

    /* ── Timeline actions ───────────────────────────────────────────────── */

    public const ACTION_SUBMITTED   = 'submitted';
    public const ACTION_RESUBMITTED = 'resubmitted';
    public const ACTION_APPROVED    = 'approved';
    public const ACTION_REJECTED    = 'rejected';
    public const ACTION_HOLD        = 'hold';
    public const ACTION_COMMENT     = 'comment';
    public const ACTION_REEXAMINED  = 'reexamined';

    public const ACTIONS = [
        self::ACTION_SUBMITTED, self::ACTION_RESUBMITTED, self::ACTION_APPROVED,
        self::ACTION_REJECTED, self::ACTION_HOLD, self::ACTION_COMMENT,
        self::ACTION_REEXAMINED,
    ];

    public const ACTION_LABELS = [
        self::ACTION_SUBMITTED   => 'Submitted for quality check',
        self::ACTION_RESUBMITTED => 'Resubmitted after hold',
        self::ACTION_APPROVED    => 'Approved',
        self::ACTION_REJECTED    => 'Rejected',
        self::ACTION_HOLD        => 'Put on hold',
        self::ACTION_COMMENT     => 'Comment',
        self::ACTION_REEXAMINED  => 'Re-examined',
    ];

    /**
     * Actions that count as one round of the back-and-forth. A comment is a
     * remark on the current round, not a new one, so it does not advance the
     * counter the 10-iteration cap watches.
     */
    public const ITERATION_ACTIONS = [self::ACTION_RESUBMITTED];

    /** Map a user's role onto the side they speak as on the timeline. */
    public static function sideForRole(?string $role): string
    {
        return match ($role) {
            'doctor'                       => self::SIDE_DOCTOR,
            'vendor', 'third_party_vendor' => self::SIDE_VENDOR,
            'admin'                        => self::SIDE_QUALITY,
            'staff'                        => self::SIDE_QUALITY,
            default                        => self::SIDE_SYSTEM,
        };
    }

    public static function isExternal(?string $origin): bool
    {
        return in_array($origin, self::EXTERNAL_ORIGINS, true);
    }

    public static function originLabel(?string $v): string
    {
        return self::ORIGIN_LABELS[$v] ?? (string) $v;
    }

    public static function actionLabel(?string $v): string
    {
        return self::ACTION_LABELS[$v] ?? (string) $v;
    }
}
