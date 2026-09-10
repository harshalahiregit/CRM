<?php

namespace Sire\Contracts;

/**
 * SIRE SDK — THE REFERENCE PEOPLE QUOTE.
 *
 * SIR-000412. What gets pasted into chat, read out on a call and printed in
 * release notes.
 *
 * OPTIONAL, AND MEANT TO STAY THAT WAY
 *
 * SIRE's own numbering works out of the box and is the expected choice. Implement
 * this only if you want SIRE references to come from the same allocator as your
 * invoices and purchase orders. SIRE must never DEPEND on a host numbering
 * service — an installation that cannot create an issue because a numbering
 * service is missing is not an installation.
 *
 * CONCURRENCY IS THE WHOLE PROBLEM
 *
 * Two people clicking Report Issue in the same second is ordinary, not an edge
 * case. `MAX(number) + 1` is the obvious implementation and it is wrong: both
 * readers see the same maximum and both write the same reference.
 *
 * Gaps are fine — a rolled-back transaction consuming a number costs nothing.
 * Reuse is not: a reference that once meant one issue must never later mean
 * another.
 */
interface SireNumberingProvider
{
    /**
     * @param  string $series 'sire_report' | 'sire_recurrence'
     * @return string formatted for display, unique within the tenant, never reused
     */
    public function next(int $tenantId, string $series): string;
}
