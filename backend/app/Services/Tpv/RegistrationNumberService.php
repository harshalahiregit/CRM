<?php

namespace App\Services\Tpv;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issues the TPV Registration Number `TPV-YYYY-NNNNN`.
 *
 * The sequence is per-tenant and per-year, incremented under a row lock inside a
 * transaction so two concurrent approvals in the same tenant/year always get
 * distinct, consecutive numbers. Prefix/padding are fixed for Phase 1; the
 * financial-year and configurable-prefix options (PRD §17) plug in here later.
 */
class RegistrationNumberService
{
    private const PREFIX  = 'TPV';
    private const PADDING = 5;

    public function generate(int $tenantId, ?Carbon $when = null): string
    {
        $year = ($when ?? now())->year;

        return DB::transaction(function () use ($tenantId, $year) {
            // Ensure the counter row exists without racing a concurrent insert.
            DB::table('tpv_registration_sequences')->insertOrIgnore([
                'tenant_id'  => $tenantId,
                'year'       => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('tpv_registration_sequences')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $next = (int) $row->last_number + 1;

            DB::table('tpv_registration_sequences')
                ->where('id', $row->id)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return sprintf('%s-%d-%0'.self::PADDING.'d', self::PREFIX, $year, $next);
        });
    }
}
