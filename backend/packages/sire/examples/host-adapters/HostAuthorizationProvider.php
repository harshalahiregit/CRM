<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireAuthorizationProvider;
use App\Support\Sire\Sdk\SireUserIdentity;
use RuntimeException;

/**
 * HostAuthorizationProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the permission check: a Gate, a policy, hasPermission(), or a role column
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 2 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['authorization' => \App\Sire\Host\HostAuthorizationProvider::class],
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
 * Fail CLOSED: an unknown capability is not granted. Returning false from can()
 * for everything is a valid working state -- SireAccessService falls through to
 * SIRE's own role rules, so SIRE stays usable while you map permissions.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostAuthorizationProvider implements SireAuthorizationProvider
{
    public function can(SireUserIdentity $user, string $capability, ?object $subject = null): bool
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAuthorizationProvider::can() is not implemented. Either finish it, or point '
            ."config('sire.providers.authorization') back at SIRE's own provider."
        );
    }

    public function roster(int $tenantId, string $roster): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostAuthorizationProvider::roster() is not implemented. Either finish it, or point '
            ."config('sire.providers.authorization') back at SIRE's own provider."
        );
    }
}
