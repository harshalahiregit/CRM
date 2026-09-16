<?php

namespace Sire\Support;

/**
 * SIRE — SLA clock states. Exactly the four the brief names.
 *
 * A stopped clock reports ON_TRACK or BREACHED depending on whether it finished
 * inside its target, and carries `met` so the UI can say "acknowledged in 12m
 * against a 30m target" without a fifth state in the vocabulary.
 */
final class SireSlaState
{
    public const ON_TRACK = 'on_track';
    public const WARNING  = 'warning';
    public const BREACHED = 'breached';
    public const PAUSED   = 'paused';

    public const ALL = [self::ON_TRACK, self::WARNING, self::BREACHED, self::PAUSED];

    public const LABELS = [
        self::ON_TRACK => 'On track',
        self::WARNING  => 'Warning',
        self::BREACHED => 'Breached',
        self::PAUSED   => 'Paused',
    ];

    /** Worst-first, for picking the overall state of a report from two clocks. */
    public const SEVERITY_RANK = [
        self::BREACHED => 3,
        self::WARNING  => 2,
        self::PAUSED   => 1,
        self::ON_TRACK => 0,
    ];

    public static function worst(?string $a, ?string $b): ?string
    {
        $states = array_values(array_filter([$a, $b]));

        if ($states === []) {
            return null;
        }

        usort($states, fn ($x, $y) => (self::SEVERITY_RANK[$y] ?? 0) <=> (self::SEVERITY_RANK[$x] ?? 0));

        return $states[0];
    }
}
