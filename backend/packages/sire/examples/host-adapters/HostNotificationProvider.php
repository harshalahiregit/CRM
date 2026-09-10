<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireNotificationProvider;
use App\Support\Sire\Sdk\SireNotification;
use RuntimeException;

/**
 * HostNotificationProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the notification service — whatever already sends 'assigned to you'
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 3 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['notification' => \App\Sire\Host\HostNotificationProvider::class],
 *
 * Until you do, SIRE runs on its own implementation and everything keeps
 * working. Wiring one provider at a time is the expected path, not a compromise.
 *
 * Every method throws until you replace it. That is deliberate: a half-finished
 * provider should stop with a message naming the method, not quietly return an
 * empty array that the UI renders as "nothing here".
 *
 * WATCH OUT
 *
 * Swallow your own exceptions: delivery must never fail a workflow transition.
 * Do NOT re-derive recipients. By the time SIRE calls you it has already removed
 * the actor, de-duplicated, collapsed low-value events and suppressed repeat SLA
 * alarms. Doing it again double-sends.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostNotificationProvider implements SireNotificationProvider
{
    public function send(SireNotification $notification): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotificationProvider::send() is not implemented. Either finish it, or point '
            ."config('sire.providers.notification') back at SIRE's own provider."
        );
    }

    public function sendMany(array $notifications): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotificationProvider::sendMany() is not implemented. Either finish it, or point '
            ."config('sire.providers.notification') back at SIRE's own provider."
        );
    }

    public function accepts(int $tenantId, int $userId, string $event): bool
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotificationProvider::accepts() is not implemented. Either finish it, or point '
            ."config('sire.providers.notification') back at SIRE's own provider."
        );
    }
}
