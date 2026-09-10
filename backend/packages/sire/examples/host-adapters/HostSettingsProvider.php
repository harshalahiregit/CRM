<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireSettingsProvider;
use RuntimeException;

/**
 * HostSettingsProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the per-tenant settings store, if there is one
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['settings' => \App\Sire\Host\HostSettingsProvider::class],
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
 * Optional -- SIRE ships its own settings table. Never throw on read: a missing
 * settings backend means SIRE runs on documented defaults, all of which are the
 * safe choice. Values include arrays, so round-trip structures, not just scalars.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostSettingsProvider implements SireSettingsProvider
{
    public function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSettingsProvider::get() is not implemented. Either finish it, or point '
            ."config('sire.providers.settings') back at SIRE's own provider."
        );
    }

    public function set(int $tenantId, string $key, mixed $value): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSettingsProvider::set() is not implemented. Either finish it, or point '
            ."config('sire.providers.settings') back at SIRE's own provider."
        );
    }

    public function all(int $tenantId, string $prefix = 'sire.'): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSettingsProvider::all() is not implemented. Either finish it, or point '
            ."config('sire.providers.settings') back at SIRE's own provider."
        );
    }

    public function forget(int $tenantId, string $key): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostSettingsProvider::forget() is not implemented. Either finish it, or point '
            ."config('sire.providers.settings') back at SIRE's own provider."
        );
    }
}
