<?php

namespace App\Support\Purchase;

/**
 * Approval lifecycle of a Purchase meeting's Minutes of Meeting (Sangoe TPV §9 —
 * "the MOM is approved before it is distributed").
 *
 * Draft → Pending Organizer → Pending Chairperson → Approved → Distributed. The
 * author submits; the organizer approves (or returns for revision); the
 * chairperson gives final approval (or returns); only once Approved may the
 * minutes be distributed (the vendor-acknowledgement send). A move not on the
 * TRANSITIONS map is refused, so minutes cannot be distributed unapproved.
 *
 * Purchase-owned mirror of the shared MomApprovalStatus — the two engines never
 * share code or tables. Stored on purchase_kickoff_meetings.mom_status.
 */
final class PurchaseMomApprovalStatus
{
    public const DRAFT = 'Draft';

    public const PENDING = 'Pending_Approval';          // awaiting organizer

    public const PENDING_CHAIR = 'Pending_Chairperson'; // organizer done, awaiting chair

    public const APPROVED = 'Approved';                 // chairperson done = final

    public const DISTRIBUTED = 'Distributed';

    public const ALL = [self::DRAFT, self::PENDING, self::PENDING_CHAIR, self::APPROVED, self::DISTRIBUTED];

    public const LABELS = [
        self::DRAFT         => 'Draft',
        self::PENDING       => 'Pending Organizer',
        self::PENDING_CHAIR => 'Pending Chairperson',
        self::APPROVED      => 'Approved',
        self::DISTRIBUTED   => 'Distributed',
    ];

    /** States where the minutes may still be distributed (approved, not yet sent). */
    public const DISTRIBUTABLE = [self::APPROVED, self::DISTRIBUTED];

    public const TRANSITIONS = [
        // Submit for approval (Draft → Organizer → Chair).
        self::DRAFT => [self::PENDING],
        // Organizer approves → awaiting chairperson, or returns for revision.
        self::PENDING => [self::PENDING_CHAIR, self::DRAFT],
        // Chairperson gives final approval, or returns for revision.
        self::PENDING_CHAIR => [self::APPROVED, self::DRAFT],
        // Distribute, or pull back for revision (needs re-approval afterwards).
        self::APPROVED => [self::DISTRIBUTED, self::DRAFT],
        // Already sent — revising means going back to Draft and round again.
        self::DISTRIBUTED => [self::DRAFT],
    ];

    public static function label(?string $v): string
    {
        return self::LABELS[$v] ?? (string) ($v ?: self::DRAFT);
    }

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }

    public static function canTransition(?string $from, ?string $to): bool
    {
        $from = $from ?: self::DRAFT;

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Whether minutes in this state may be distributed to the vendor. */
    public static function isDistributable(?string $v): bool
    {
        return in_array($v ?: self::DRAFT, self::DISTRIBUTABLE, true);
    }
}
