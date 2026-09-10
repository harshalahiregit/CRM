<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireAuditProvider;
use App\Support\Sire\Sdk\SireAuditEvent;
use RuntimeException;

/**
 * HostAuditProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the audit trail, if there is one
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 3 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['audit' => \App\Sire\Host\HostAuditProvider::class],
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
 * Swallow write failures -- audit is evidence, not flow control. There is no
 * update() and no delete() on this contract, and adding one defeats its purpose:
 * release approvals and emergency overrides are defended by this trail.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostAuditProvider implements SireAuditProvider
{
    public function record(SireAuditEvent $event): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAuditProvider::record() is not implemented. Either finish it, or point '
            ."config('sire.providers.audit') back at SIRE's own provider."
        );
    }

    public function recordMany(array $events): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAuditProvider::recordMany() is not implemented. Either finish it, or point '
            ."config('sire.providers.audit') back at SIRE's own provider."
        );
    }

    public function for(string $subjectType, int $subjectId, int $tenantId, int $limit = 200): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAuditProvider::for() is not implemented. Either finish it, or point '
            ."config('sire.providers.audit') back at SIRE's own provider."
        );
    }
}
