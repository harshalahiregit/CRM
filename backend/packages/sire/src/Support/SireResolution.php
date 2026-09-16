<?php

namespace Sire\Support;

/**
 * SIRE — why an issue stopped. Set alongside a terminal status, cleared on reopen.
 * Separate from `status` so "closed" can be counted without asking why, and
 * "closed because it was a duplicate" can be counted when you do ask.
 */
final class SireResolution
{
    public const FIXED            = 'fixed';
    public const DUPLICATE        = 'duplicate';
    public const REJECTED         = 'rejected';
    public const WONT_FIX         = 'wont_fix';
    public const CANNOT_REPRODUCE = 'cannot_reproduce';

    public const ALL = [
        self::FIXED, self::DUPLICATE, self::REJECTED, self::WONT_FIX, self::CANNOT_REPRODUCE,
    ];

    /** Terminal status => the resolution it implies. */
    public const FOR_STATUS = [
        SireStatus::CLOSED           => self::FIXED,
        SireStatus::DUPLICATE        => self::DUPLICATE,
        SireStatus::REJECTED         => self::REJECTED,
        SireStatus::WONT_FIX         => self::WONT_FIX,
        SireStatus::CANNOT_REPRODUCE => self::CANNOT_REPRODUCE,
    ];
}
