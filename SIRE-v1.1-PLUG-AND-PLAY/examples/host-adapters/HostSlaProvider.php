<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireSlaProvider;
use RuntimeException;

/**
 * HostSlaProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: a service-level configuration screen, if one exists
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['sla' => \App\Sire\Host\HostSlaProvider::class],
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
 * You probably should NOT implement this. SIRE owns SLA behaviour outright; this
 * supplies inputs only, and exists for hosts that already have a service-level
 * configuration screen. Returning [] means 'not configured', which SIRE reports
 * as ON_TRACK with no target.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostSlaProvider implements SireSlaProvider
{
    public function policies(int $tenantId): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSlaProvider::policies() is not implemented. Either finish it, or point '
            ."config('sire.providers.sla') back at SIRE's own provider."
        );
    }

    public function warningThreshold(int $tenantId): float
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSlaProvider::warningThreshold() is not implemented. Either finish it, or point '
            ."config('sire.providers.sla') back at SIRE's own provider."
        );
    }

    public function pauseStates(int $tenantId): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSlaProvider::pauseStates() is not implemented. Either finish it, or point '
            ."config('sire.providers.sla') back at SIRE's own provider."
        );
    }

    public function businessCalendar(int $tenantId): ?array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSlaProvider::businessCalendar() is not implemented. Either finish it, or point '
            ."config('sire.providers.sla') back at SIRE's own provider."
        );
    }
}
