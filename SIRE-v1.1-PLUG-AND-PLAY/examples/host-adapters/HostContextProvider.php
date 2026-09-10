<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireContextProvider;
use App\Support\Sire\Sdk\SireScreenContext;
use RuntimeException;

/**
 * HostContextProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the frontend route registry, or wherever screens are declared
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['context' => \App\Sire\Host\HostContextProvider::class],
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
 * This is what makes Report Issue one click instead of a form. No match must NEVER
 * block a report: return confidence 'low' and let the user correct it. A screen
 * can also declare itself from the SPA with SireContext.register({...}), which
 * always wins over inference.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostContextProvider implements SireContextProvider
{
    public function resolve(string $path): SireScreenContext
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostContextProvider::resolve() is not implemented. Either finish it, or point '
            ."config('sire.providers.context') back at SIRE's own provider."
        );
    }

    public function modules(): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostContextProvider::modules() is not implemented. Either finish it, or point '
            ."config('sire.providers.context') back at SIRE's own provider."
        );
    }

    public function moduleLabel(string $module): string
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostContextProvider::moduleLabel() is not implemented. Either finish it, or point '
            ."config('sire.providers.context') back at SIRE's own provider."
        );
    }

    public function routeMap(): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostContextProvider::routeMap() is not implemented. Either finish it, or point '
            ."config('sire.providers.context') back at SIRE's own provider."
        );
    }
}
