<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireNumberingProvider;
use RuntimeException;

/**
 * HostNumberingProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the document numbering service — whatever produces INV-2026-0041
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 1 method below, then point config/sire.php at this class:
 *
 *     'providers' => ['numbering' => \App\Sire\Host\HostNumberingProvider::class],
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
 * Optional -- SIRE's own numbering works out of the box. Concurrency is the whole
 * problem: two people clicking Report Issue in the same second is ordinary, and
 * MAX(number)+1 is the obvious implementation and it is wrong. Gaps are fine;
 * reuse is not.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostNumberingProvider implements SireNumberingProvider
{
    public function next(int $tenantId, string $series): string
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNumberingProvider::next() is not implemented. Either finish it, or point '
            ."config('sire.providers.numbering') back at SIRE's own provider."
        );
    }
}
