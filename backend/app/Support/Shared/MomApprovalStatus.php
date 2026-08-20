<?php

namespace App\Support\Shared;

/**
 * Approval lifecycle of a meeting's Minutes of Meeting (Meeting.docx — "the MOM
 * is approved before it is distributed").
 *
 * Draft → Pending Approval → Approved → Distributed. The author submits; an
 * approver approves or returns it for revision; only once Approved may the
 * minutes be distributed (the vendor-acknowledgement send). A move not on the
 * TRANSITIONS map is refused, so minutes cannot be distributed unapproved.
 */
final class MomApprovalStatus
{
    public const DRAFT       = 'Draft';
    public const PENDING     = 'Pending_Approval';
    public const APPROVED    = 'Approved';
    public const DISTRIBUTED = 'Distributed';

    public const ALL = [self::DRAFT, self::PENDING, self::APPROVED, self::DISTRIBUTED];

    public const LABELS = [
        self::DRAFT       => 'Draft',
        self::PENDING     => 'Pending Approval',
        self::APPROVED    => 'Approved',
        self::DISTRIBUTED => 'Distributed',
    ];

    /** States where the minutes may still be distributed (approved, not yet sent). */
    public const DISTRIBUTABLE = [self::APPROVED, self::DISTRIBUTED];

    public const TRANSITIONS = [
        // Submit for approval.
        self::DRAFT       => [self::PENDING],
        // Approve, or return for revision (→ Draft).
        self::PENDING     => [self::APPROVED, self::DRAFT],
        // Distribute, or pull back for revision (needs re-approval afterwards).
        self::APPROVED    => [self::DISTRIBUTED, self::DRAFT],
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
