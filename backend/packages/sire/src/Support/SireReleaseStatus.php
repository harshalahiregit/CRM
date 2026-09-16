<?php

namespace Sire\Support;

/**
 * SIRE — release governance lifecycle.
 *
 * READY and BLOCKED are DERIVED, not set. You do not mark a release ready; you
 * make its gates pass. SireReleaseGateService recomputes the pair on every
 * relevant change, which is why neither appears as a target in TRANSITIONS.
 *
 * APPROVED, RELEASED and CANCELLED are decisions a person takes, and each one is
 * audited.
 *
 * ROLLED_BACK is a sixth state the brief did not name. It was built in Phase 2 and
 * is kept because it is genuinely distinct: CANCELLED means it never shipped,
 * ROLLED_BACK means it shipped and was withdrawn. Collapsing them would lose the
 * only fact that matters when someone asks whether customers ever had it.
 */
final class SireReleaseStatus
{
    /** Gates pass — the release may be approved. Derived. */
    public const READY = 'ready';

    /** At least one blocking gate fails. Derived. */
    public const BLOCKED = 'blocked';

    public const APPROVED    = 'approved';
    public const RELEASED    = 'released';
    public const CANCELLED   = 'cancelled';
    public const ROLLED_BACK = 'rolled_back';

    public const ALL = [
        self::BLOCKED, self::READY, self::APPROVED,
        self::RELEASED, self::CANCELLED, self::ROLLED_BACK,
    ];

    public const LABELS = [
        self::BLOCKED     => 'Blocked',
        self::READY       => 'Ready',
        self::APPROVED    => 'Approved',
        self::RELEASED    => 'Released',
        self::CANCELLED   => 'Cancelled',
        self::ROLLED_BACK => 'Rolled back',
    ];

    /** Statuses whose gate state is still recomputed as issues move. */
    public const GATE_DERIVED = [self::READY, self::BLOCKED];

    /** Nothing moves out of these. */
    public const TERMINAL = [self::CANCELLED, self::ROLLED_BACK];

    /**
     * action => from[], to, label, capability
     *
     * Note what is absent: nothing transitions TO ready or blocked. Those come
     * from the gate engine, and offering a button for them would let someone
     * declare a release ready without making it so.
     */
    public const TRANSITIONS = [
        'approve' => [
            'from' => [self::READY], 'to' => self::APPROVED,
            'label' => 'Approve release', 'capability' => 'sire.release.approve',
            'note' => 'Only from READY. A blocked release must pass its gates or carry an authorised override.',
        ],
        'revoke_approval' => [
            'from' => [self::APPROVED], 'to' => self::READY,
            'label' => 'Revoke approval', 'capability' => 'sire.release.approve',
        ],
        'release' => [
            'from' => [self::APPROVED], 'to' => self::RELEASED,
            'label' => 'Mark released', 'capability' => 'sire.release.manage',
            'note' => 'SIRE records that a release happened. It does not perform one.',
        ],
        'cancel' => [
            'from' => [self::BLOCKED, self::READY, self::APPROVED], 'to' => self::CANCELLED,
            'label' => 'Cancel release', 'capability' => 'sire.release.manage',
            'requires' => ['reason'],
        ],
        'roll_back' => [
            'from' => [self::RELEASED], 'to' => self::ROLLED_BACK,
            'label' => 'Roll back', 'capability' => 'sire.release.manage',
            'requires' => ['reason'],
            'note' => 'Clears released_version_id on every issue in the release — they are not in production any more.',
        ],
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function allows(string $from, string $action): bool
    {
        $t = self::TRANSITIONS[$action] ?? null;

        return $t !== null && in_array($from, $t['from'], true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** A release still being governed: gates matter, issues can still move. */
    public static function isOpen(string $status): bool
    {
        return in_array($status, [self::BLOCKED, self::READY, self::APPROVED], true);
    }
}
