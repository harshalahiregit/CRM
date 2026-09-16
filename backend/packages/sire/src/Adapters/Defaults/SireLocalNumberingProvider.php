<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireNumberingProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The shipped numbering provider: SIR-000412, allocated per tenant.
 *
 * This is the expected choice. A host provider exists only for installations
 * that want SIRE references to come from the same allocator as their invoices;
 * SIRE must never DEPEND on one, because an installation that cannot create an
 * issue for want of a numbering service is not an installation.
 *
 * CONCURRENCY IS THE WHOLE PROBLEM
 *
 * Two people clicking Report Issue in the same second is ordinary. Reading
 * MAX(number) and adding one is the obvious implementation and it is wrong —
 * both readers see the same maximum and both write the same reference.
 *
 * So allocation happens inside a transaction with a row lock on the tenant's
 * current highest row (lockForUpdate). The second writer blocks until the first
 * commits, then reads the new maximum. On SQLite, where lockForUpdate is a no-op,
 * the surrounding transaction plus a unique index make a collision an error
 * rather than a duplicate, and the retry below resolves it.
 *
 * Gaps are acceptable — a rolled-back transaction consumes a number and that is
 * fine. Reuse is not: a reference that once meant one issue must never later mean
 * another.
 */
class SireLocalNumberingProvider implements SireNumberingProvider
{
    /**
     * Prefixes are SIRE's own, not the host's. SIR reads as an issue reference in
     * any product; a host-specific prefix would have to be configured before the
     * first issue could be created.
     */
    private const SERIES = [
        'sire_report'     => ['table' => 'sire_reports',           'column' => 'report_number', 'prefix' => 'SIR'],
        'sire_recurrence' => ['table' => 'sire_recurrence_groups', 'column' => 'reference',     'prefix' => 'SRG'],
    ];

    private const MAX_ATTEMPTS = 3;

    public function next(int $tenantId, string $series): string
    {
        if (! isset(self::SERIES[$series])) {
            throw new RuntimeException("SIRE: unknown numbering series '{$series}'.");
        }

        $spec = self::SERIES[$series];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($spec, $tenantId) {
                    $prefix = $spec['prefix'].'-';

                    $highest = DB::table($spec['table'])
                        ->where('tenant_id', $tenantId)
                        ->where($spec['column'], 'like', $prefix.'%')
                        ->orderByDesc($spec['column'])
                        ->lockForUpdate()
                        ->value($spec['column']);

                    $sequence = $highest === null
                        ? 1
                        : ((int) substr((string) $highest, strlen($prefix))) + 1;

                    return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
                });
            } catch (\Throwable $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('SIRE: could not allocate a reference.'); // unreachable
    }
}
