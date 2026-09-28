<?php

namespace Sire\Support;

/**
 * SIRE — which workflow a report runs.
 *
 * DEFECT  something is broken.      new → triaged → assigned → development → QA → release
 * CHANGE  something should differ.  new → triaged → business review → impact analysis
 *                                   → approval → planned → ASSIGNED, and from there it is
 *                                   indistinguishable from a defect.
 *
 * One entity, two entry paths, one shared pipeline. A separate change_requests
 * table would have meant a second numbering series, a second timeline, a second
 * SLA implementation and a second dashboard — for a record that becomes ordinary
 * engineering work the moment it is approved.
 */
final class SireTrack
{
    public const DEFECT = 'defect';
    public const CHANGE = 'change';

    public const ALL = [self::DEFECT, self::CHANGE];

    public const LABELS = [
        self::DEFECT => 'Defect',
        self::CHANGE => 'Change request',
    ];

    public static function label(?string $track): string
    {
        return self::LABELS[$track] ?? self::LABELS[self::DEFECT];
    }
}
