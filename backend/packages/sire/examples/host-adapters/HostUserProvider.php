<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireUserProvider;
use App\Support\Sire\Sdk\SireUserIdentity;
use RuntimeException;

/**
 * HostUserProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the authenticated user, and how to look one up by id
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['user' => \App\Sire\Host\HostUserProvider::class],
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
 * lookup() and lookupMany() MUST be tenant-scoped: an unscoped lookup turns a
 * SIRE issue page into a way to enumerate another tenant's staff. Return the four
 * fields SireUserIdentity asks for and nothing else -- SIRE has no use for an
 * email or a preferences blob, and cannot leak what it never receives.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostUserProvider implements SireUserProvider
{
    public function currentUser(): ?SireUserIdentity
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostUserProvider::currentUser() is not implemented. Either finish it, or point '
            ."config('sire.providers.user') back at SIRE's own provider."
        );
    }

    public function lookup(int $tenantId, int $userId): ?SireUserIdentity
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostUserProvider::lookup() is not implemented. Either finish it, or point '
            ."config('sire.providers.user') back at SIRE's own provider."
        );
    }

    public function lookupMany(int $tenantId, array $userIds): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostUserProvider::lookupMany() is not implemented. Either finish it, or point '
            ."config('sire.providers.user') back at SIRE's own provider."
        );
    }

    /**
     * Everyone who may be given engineering work.
     *
     * Return [] if your application cannot enumerate users and SIRE falls back
     * to the rosters in `sire.roles.*`. TENANT-SCOPE IT: an unscoped directory
     * turns the assignment picker into a way to read another tenant's staff
     * list.
     *
     * @return array<int, SireUserIdentity>
     */
    public function directory(int $tenantId, ?string $search = null, int $limit = 200): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostUserProvider::directory() is not implemented. Either finish it, or point '
            ."config('sire.providers.user') back at SIRE's own provider."
        );
    }

    public function isActive(int $tenantId, int $userId): bool
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostUserProvider::isActive() is not implemented. Either finish it, or point '
            ."config('sire.providers.user') back at SIRE's own provider."
        );
    }
}
