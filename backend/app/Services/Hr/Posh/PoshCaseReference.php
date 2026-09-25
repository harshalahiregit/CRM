<?php

namespace App\Services\Hr\Posh;

use App\Models\Hr\HrPoshCase;

/**
 * The next POSH-n for a workspace.
 *
 * The same shape HrAdvance::nextReference() uses: read the highest numeric
 * suffix this tenant has, add one. Soft-deleted cases are counted, so a
 * removed case never has its number handed to another — two cases sharing a
 * reference in a set of harassment records would be worse than a gap.
 *
 * The real guarantee is the unique (tenant_id, reference) index, not this
 * method. Two intakes racing will both compute the same number and one will
 * lose on the constraint; the caller retries. Reading the maximum inside the
 * transaction narrows the window but does not close it, and pretending
 * otherwise would be the bug.
 */
class PoshCaseReference
{
    private const PREFIX = 'POSH-';

    public function next(int $tenantId): string
    {
        $len = strlen(self::PREFIX);

        $max = (int) HrPoshCase::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('reference', 'like', self::PREFIX.'%')
            ->selectRaw('MAX(CAST(SUBSTR(reference, '.($len + 1).') AS UNSIGNED)) AS n')
            ->value('n');

        return self::PREFIX.($max + 1);
    }
}
