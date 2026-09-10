<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireVersionProvider;
use RuntimeException;

/**
 * HostVersionProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: wherever the deployed application version is known
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['version' => \App\Sire\Host\HostVersionProvider::class],
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
 * Optional. Return null rather than guessing -- a null version is honest, a wrong
 * one poisons every release dashboard. detected_version must be automatic:
 * asking a user which build they were on is asking them to look it up.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostVersionProvider implements SireVersionProvider
{
    public function current(int $tenantId): ?string
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostVersionProvider::current() is not implemented. Either finish it, or point '
            ."config('sire.providers.version') back at SIRE's own provider."
        );
    }

    public function environment(): ?string
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostVersionProvider::environment() is not implemented. Either finish it, or point '
            ."config('sire.providers.version') back at SIRE's own provider."
        );
    }

    public function known(int $tenantId): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostVersionProvider::known() is not implemented. Either finish it, or point '
            ."config('sire.providers.version') back at SIRE's own provider."
        );
    }

    public function normalize(?string $raw): ?string
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostVersionProvider::normalize() is not implemented. Either finish it, or point '
            ."config('sire.providers.version') back at SIRE's own provider."
        );
    }
}
