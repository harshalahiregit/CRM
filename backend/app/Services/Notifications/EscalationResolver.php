<?php

namespace App\Services\Notifications;

/**
 * Escalation resolver (Central Notification Engine). Maps days-overdue to a
 * recipient role using a configuration-driven ladder (rule override → config
 * default). No hardcoded escalation: e.g. Day 1 HR, Day 3 HR Manager, Day 7
 * Department Head, Day 15 Administrator — all from config/rules.
 */
class EscalationResolver
{
    public function __construct(private ModuleEventCatalog $catalog)
    {
    }

    /**
     * The escalation role for a given number of days overdue, or null if not yet
     * escalated. Uses the rule's escalation_days ladder if set, else the config ladder.
     */
    public function roleForOverdue(int $daysOverdue, ?array $ruleLadder = null): ?string
    {
        $ladder = ! empty($ruleLadder) ? $ruleLadder : $this->catalog->escalationLadder();
        // Ladder entries: ['days'=>N,'role'=>X]. Highest threshold ≤ daysOverdue wins.
        $role = null; $best = -1;
        foreach ($ladder as $step) {
            $d = (int) ($step['days'] ?? 0);
            if ($daysOverdue >= $d && $d >= $best) {
                $best = $d;
                $role = $step['role'] ?? null;
            }
        }

        return $role;
    }
}
