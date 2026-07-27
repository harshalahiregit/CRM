<?php

namespace App\Support\Hr;

/**
 * SPK-1 interview sequence: HR → Technical → Manager → Client → Final.
 *
 * round_name has always been free text, so historic rounds ("Technical L1",
 * "Manager L2", "HR Screening", …) are mapped onto the sequence by pattern
 * rather than an exact list. Mirrors canonicalRound() in the React constants.
 */
class InterviewSequence
{
    public const ORDER = ['HR Round', 'Technical Round', 'Manager Round', 'Client Round', 'Final Round'];

    /** Map a free-text round name onto the canonical sequence (or return it unchanged). */
    public static function canonical(?string $round): ?string
    {
        if (! $round) {
            return $round;
        }
        if (in_array($round, self::ORDER, true)) {
            return $round;
        }

        $s = strtolower($round);

        // Order matters: "HR Final" must resolve to Final, not HR.
        return match (true) {
            str_contains($s, 'final')                                  => 'Final Round',
            str_contains($s, 'client')                                 => 'Client Round',
            str_contains($s, 'manager'), str_contains($s, 'managerial') => 'Manager Round',
            str_contains($s, 'technical'), str_contains($s, 'tech')    => 'Technical Round',
            str_contains($s, 'hr'), str_contains($s, 'screening')      => 'HR Round',
            default                                                    => $round,   // unknown → unsequenced
        };
    }

    /** Position in the sequence, or null when the round is not part of it. */
    public static function indexOf(?string $round): ?int
    {
        $i = array_search(self::canonical($round), self::ORDER, true);

        return $i === false ? null : $i;
    }
}
