<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireKnowledgeProvider;
use App\Support\Sire\Sdk\SireKnowledgeArticle;
use App\Support\Sire\Sdk\SireUserIdentity;
use RuntimeException;

/**
 * HostKnowledgeProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: the knowledge base
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['knowledge' => \App\Sire\Host\HostKnowledgeProvider::class],
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
 * The one provider whose miswiring is SILENT: every call is wrapped so a missing
 * KB degrades to 'no related articles', which looks identical to a wrong
 * implementation. Verify with a search you KNOW should hit. search() must be
 * tenant-scoped; createDraft() must never publish.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostKnowledgeProvider implements SireKnowledgeProvider
{
    public function find(int $tenantId, int|string $articleId): ?SireKnowledgeArticle
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostKnowledgeProvider::find() is not implemented. Either finish it, or point '
            ."config('sire.providers.knowledge') back at SIRE's own provider."
        );
    }

    public function search(int $tenantId, string $query, int $limit = 5): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostKnowledgeProvider::search() is not implemented. Either finish it, or point '
            ."config('sire.providers.knowledge') back at SIRE's own provider."
        );
    }

    public function createDraft(int $tenantId, array $attributes, SireUserIdentity $author): SireKnowledgeArticle
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostKnowledgeProvider::createDraft() is not implemented. Either finish it, or point '
            ."config('sire.providers.knowledge') back at SIRE's own provider."
        );
    }

    public function isAvailable(): bool
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostKnowledgeProvider::isAvailable() is not implemented. Either finish it, or point '
            ."config('sire.providers.knowledge') back at SIRE's own provider."
        );
    }
}
