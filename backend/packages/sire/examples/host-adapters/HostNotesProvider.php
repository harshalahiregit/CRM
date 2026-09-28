<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireNotesProvider;
use App\Support\Sire\Sdk\SireNote;
use App\Support\Sire\Sdk\SireUserIdentity;
use RuntimeException;

/**
 * HostNotesProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the notes or comments system, if there is one
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 5 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['notes' => \App\Sire\Host\HostNotesProvider::class],
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
 * find() MUST return subjectType, subjectId and tenantId. Note ids are a global
 * sequence, and SIRE uses those to check a note in a URL really belongs to the
 * report in that same URL. Return updatedAt only when genuinely edited -- SIRE
 * renders an 'edited' marker from its presence.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostNotesProvider implements SireNotesProvider
{
    public function add(object $subject, string $body, SireUserIdentity $author, bool $internal = true): SireNote
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotesProvider::add() is not implemented. Either finish it, or point '
            ."config('sire.providers.notes') back at SIRE's own provider."
        );
    }

    public function listFor(object $subject, ?SireUserIdentity $viewer = null, int $limit = 200): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotesProvider::listFor() is not implemented. Either finish it, or point '
            ."config('sire.providers.notes') back at SIRE's own provider."
        );
    }

    public function find(int|string $noteId): ?SireNote
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotesProvider::find() is not implemented. Either finish it, or point '
            ."config('sire.providers.notes') back at SIRE's own provider."
        );
    }

    public function update(int|string $noteId, string $body, SireUserIdentity $user): SireNote
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotesProvider::update() is not implemented. Either finish it, or point '
            ."config('sire.providers.notes') back at SIRE's own provider."
        );
    }

    public function delete(int|string $noteId, SireUserIdentity $user): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostNotesProvider::delete() is not implemented. Either finish it, or point '
            ."config('sire.providers.notes') back at SIRE's own provider."
        );
    }
}
