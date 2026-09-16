<?php

namespace App\Support\Transport;

/**
 * The verification lifecycle of a trip document.  SNG-TRN-014.  D-59.
 *
 * ── CONSTRUCTED, NOT QUOTED ──────────────────────────────────────────────
 * Step 11 registers eight enums (ENUM-001..008) and four state machines
 * (SM-ADV, SM-EXC, SM-ORD, SM-TRP). **Not one of them describes a document.**
 * ENUM-006 is document_TYPE — `lr|ewaybill|invoice|pod|...` — which says what a
 * document IS, never whether anyone has checked it.
 *
 * Something has to answer that question, because STT-008 is LOCKED and its
 * guard is "POD valid":
 *
 *   STT-008 | SM-TRP | delivered -> pod_verified | Verify POD | DocumentEngine
 *           | guard "POD valid" | effect "Unlock billing" | audited | LOCKED
 *
 * and SNG-TRN-014's acceptance criterion is "POD required before billable state
 * unless approved exception". A registry that asks for a validity guard and
 * registers no validity vocabulary has a gap, and this is the narrowest thing
 * that fills it: three states, no more than STT-008 needs.
 *
 * STOS-DOC describes eighteen document statuses. They are NOT reproduced here.
 * Picking three of eighteen would be a product decision; naming only the states
 * this ticket's own transition requires is an implementation one. When the
 * registry gains a document lifecycle, this is the single place it lands.
 *
 * ── WHY `rejected` IS TERMINAL AND THE ROW SURVIVES ──────────────────────
 * A rejected POD is not deleted and is not re-verified. The next attempt is a
 * NEW row, and the rejected one stays with its reason. Two reasons: the file is
 * evidence that somebody submitted something unacceptable, and re-verifying in
 * place would erase the first decision and the person who made it — which is
 * exactly what STT-008's "audited: Yes" is there to prevent.
 */
final class TripDocumentStatus
{
    /** Filed, nobody has looked at it yet. CTR-012 lets it still be replaced here. */
    public const RECEIVED = 'received';

    /** Checked and accepted. STT-008's guard passes on this, and only this. */
    public const VERIFIED = 'verified';

    /** Checked and refused, with a reason. Terminal — a replacement is a new row. */
    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const ALL = [self::RECEIVED, self::VERIFIED, self::REJECTED];

    public const INITIAL = self::RECEIVED;

    /**
     * The only edges this ticket builds preconditions for.
     *
     * Same discipline as TripStatus and AdvanceStatus: a state is declared so
     * the vocabulary is whole, and an edge is wired only where something
     * actually enforces it. `verified` and `rejected` are both terminal.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::RECEIVED => [self::VERIFIED, self::REJECTED],
    ];

    /** Statuses that satisfy STT-008's "POD valid" guard. */
    public const SATISFIES_BILLING_GATE = [self::VERIFIED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** True once a decision has been made and CTR-012's immutability bites. */
    public static function isDecided(string $status): bool
    {
        return $status === self::VERIFIED || $status === self::REJECTED;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::RECEIVED => 'Received',
            self::VERIFIED => 'Verified',
            self::REJECTED => 'Rejected',
            default        => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
