<?php

namespace App\Domains\Fleet\Contracts;

/**
 * Where the people STOS can put behind a wheel come from.
 *
 * This interface is the ONLY thing STOS knows about the customer directory.
 * That is what lets the module satisfy two requirements that pull in opposite
 * directions:
 *
 *   • Integrated into the CRM — drivers are read live from the existing
 *     customer/vendor directories, so 40 workers added under a vendor appear in
 *     Transport with nobody re-entering them.
 *   • Standalone — the same screens run against a STOS-local table when there
 *     is no CRM around them.
 *
 * Swapping the implementation swaps the source. Nothing above this line has to
 * know which one is in use, which is why no STOS service, controller or screen
 * imports a CRM model anywhere.
 *
 * Every implementation returns rows of the same shape:
 *
 *   [
 *     'source'        => 'crm_tpv_worker',   // which directory
 *     'source_id'     => 17,                 // the id within it
 *     'ref'           => 'crm_tpv_worker:17',// the stable handle STOS stores
 *     'name'          => 'Rajesh Kumar',
 *     'phone'         => '9876543210',
 *     'designation'   => 'Driver',
 *     'employer'      => 'Sharma Transport Pvt Ltd',
 *     'employer_type' => 'vendor' | 'customer' | null,
 *   ]
 */
interface DriverDirectory
{
    /**
     * People available to this workspace.
     *
     * @param  array  $filters  q (name/phone search), drivers_only (bool)
     * @return array<int, array>
     */
    public function people(int $companyId, array $filters = []): array;

    /** One person by their `source:source_id` handle, or null. */
    public function find(int $companyId, string $source, int $sourceId): ?array;

    /** What this implementation is reading, for the UI to be honest about. */
    public function describe(): string;
}
