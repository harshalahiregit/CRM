<?php

namespace App\Support\Contract;

/**
 * Where a contract is in its life.
 *
 * SIGNED and ACTIVE are separate on purpose. Signed means both parties have put
 * their names to it; active means it is in force today, which also depends on
 * the start date. A contract signed in March for an April start is signed but
 * not yet active, and anything asking "may we work under this" wants the second
 * question, not the first.
 */
final class ContractStatus
{
    public const DRAFT     = 'draft';
    public const SENT      = 'sent';
    public const SIGNED    = 'signed';
    public const ACTIVE    = 'active';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::DRAFT, self::SENT, self::SIGNED,
        self::ACTIVE, self::EXPIRED, self::CANCELLED,
    ];

    /** States in which a contract may still be edited or signed. */
    public const OPEN = [self::DRAFT, self::SENT, self::SIGNED, self::ACTIVE];

    /** A cancelled contract is not "not yet signed" -- it is over. */
    public const CLOSED = [self::EXPIRED, self::CANCELLED];

    public static function isValid(?string $s): bool
    {
        return $s !== null && in_array($s, self::ALL, true);
    }
}
