<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireNotificationProvider;
use Sire\Dto\SireNotification;
use Illuminate\Support\Facades\Log;

/**
 * The shipped notification provider: records the decision, delivers nothing.
 *
 * A working null object, not an unfinished one. By the time SIRE reaches here it
 * has already done the part only SIRE could do — decided who should hear about
 * the event, removed the actor, de-duplicated recipients, collapsed low-value
 * events and suppressed repeat SLA alarms. What remains is channel, template,
 * locale and preference, all of which belong to the host.
 *
 * WHY NOT SEND MAIL BY DEFAULT
 *
 * Because SIRE cannot know whether this installation is a production system with
 * real staff or a developer's first hour with the package. A module that starts
 * emailing people the moment it is installed has made an assumption it had no
 * right to make. Logging is the honest default: every intended notification is
 * visible, and nobody's inbox is involved.
 *
 * It is also the fastest way to verify the fan-out rules — tail the log, move an
 * issue through QA, and read exactly who SIRE decided to tell.
 *
 * accepts() returns true for everything. An unwired preference store must not
 * silence SIRE: a missed "QA failed" stalls an issue nobody is watching.
 */
class SireLocalNotificationProvider implements SireNotificationProvider
{
    public function send(SireNotification $notification): void
    {
        $this->write([$notification]);
    }

    public function sendMany(array $notifications): void
    {
        $this->write($notifications);
    }

    public function accepts(int $tenantId, int $userId, string $event): bool
    {
        return true;
    }

    /** @param array<int, SireNotification> $notifications */
    private function write(array $notifications): void
    {
        if ($notifications === []) {
            return;
        }

        try {
            $first = $notifications[0];

            Log::channel((string) config('sire.log_channel'))->info('sire.notify', [
                'tenant'     => $first->tenantId,
                'event'      => $first->event,
                'priority'   => $first->priority,
                'title'      => $first->title,
                'url'        => $first->url,
                'recipients' => array_map(fn (SireNotification $n) => $n->recipientId, $notifications),
                'metadata'   => $first->metadata,
            ]);
        } catch (\Throwable $e) {
            // Even the null provider swallows: delivery must never be able to
            // fail a workflow transition.
            report($e);
        }
    }
}
