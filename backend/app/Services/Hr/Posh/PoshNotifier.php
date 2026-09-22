<?php

namespace App\Services\Hr\Posh;

use App\Models\Hr\HrPoshCase;
use App\Models\User;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Support\Facades\Log;

/**
 * Telling case members that something needs them.
 *
 * EXISTENCE ONLY. A body says a reference and what is wanted — never the
 * complainant, the respondent, the narrative, the evidence or the finding. A
 * notification is read on a phone lock screen and forwarded without thinking,
 * so it carries the least that is still useful: which case, and that it wants
 * attention.
 *
 * USER-ADDRESSED ONLY. Role addressing exists in the engine and is deliberately
 * not used: no POSH role exists in the notification vocabulary, and inventing
 * one would mean a population resolved somewhere other than case membership.
 * Every recipient here is an explicit user id that came from the case's own
 * roster.
 *
 * NEVER THROWS. A committee member missing a bell is a worse outcome than a
 * failed request only until the request is the one that opened the inquiry.
 */
class PoshNotifier
{
    public function __construct(private NotificationEngine $engine)
    {
    }

    public function membersAdded(HrPoshCase $case, array $userIds, ?User $actor = null): void
    {
        $this->send($case, $userIds, 'Member Added', $actor);
    }

    public function inquiryOpened(HrPoshCase $case, array $userIds, ?User $actor = null): void
    {
        $this->send($case, $userIds, 'Inquiry Opened', $actor);
    }

    public function decisionRequired(HrPoshCase $case, array $userIds, ?User $actor = null): void
    {
        $this->send($case, $userIds, 'Decision Required', $actor);
    }

    public function inquiryConcluded(HrPoshCase $case, array $userIds, ?User $actor = null): void
    {
        $this->send($case, $userIds, 'Inquiry Concluded', $actor);
    }

    /**
     * One notification, to named people.
     *
     * The context carries the REFERENCE and nothing else, so a template edited
     * by a workspace cannot interpolate case detail that was never supplied.
     */
    private function send(HrPoshCase $case, array $userIds, string $event, ?User $actor): void
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));

        if ($userIds === []) {
            return;
        }

        try {
            $this->engine->dispatch((int) $case->tenant_id, 'Posh', $event, [
                'recipient_user_ids' => $userIds,
                'context'            => ['reference' => $case->reference],
                // No entity_type or entity_id: those are read back by generic
                // screens, and a POSH case must not become reachable through
                // one.
            ], $actor);
        } catch (\Throwable $e) {
            Log::channel('hr')->warning('POSH notification failed: '.$e->getMessage(), [
                'case_id' => $case->id, 'event' => $event,
            ]);
        }
    }
}
