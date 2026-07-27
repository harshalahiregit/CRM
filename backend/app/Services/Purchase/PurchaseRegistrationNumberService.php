<?php

namespace App\Services\Purchase;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issues the Purchase-vendor Registration Number `PUR-YYYY-NNNNN`.
 *
 * Per-tenant/per-year sequence incremented under a row lock inside a
 * transaction, so concurrent approvals in the same tenant/year get distinct,
 * consecutive numbers. Isolated from the TPV sequence (own table) so the two
 * modules never collide.
 */
class PurchaseRegistrationNumberService
{
    private const PREFIX  = 'PUR';
    private const PADDING = 5;

    public function generate(int $tenantId, ?Carbon $when = null): string
    {
        $year = ($when ?? now())->year;

        return DB::transaction(function () use ($tenantId, $year) {
            DB::table('purchase_registration_sequences')->insertOrIgnore([
                'tenant_id'   => $tenantId,
                'year'        => $year,
                'last_number' => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $row = DB::table('purchase_registration_sequences')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $next = (int) $row->last_number + 1;

            DB::table('purchase_registration_sequences')
                ->where('id', $row->id)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return sprintf('%s-%d-%0'.self::PADDING.'d', self::PREFIX, $year, $next);
        });
    }
}
