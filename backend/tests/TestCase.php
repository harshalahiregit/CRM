<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A counter that never repeats inside one test run — D-54.
     *
     * Fixtures used to draw identifiers randomly: `random_int(1000, 9999)`
     * against `UNIQUE(tenant_id, registration_normalized)` is 9,000 possible
     * values, so two vehicles built in the same test method could collide and
     * the suite failed roughly once in four full runs.
     *
     * Widening the range would have made that rarer, not impossible, and a
     * rare failure is worse than a frequent one because it gets re-run instead
     * of fixed. This counter cannot collide: it is monotonic for the life of
     * the process, and Laravel's parallel testing gives each process its own
     * database, so processes never share a key space.
     *
     * `$width` keeps the generated value the same shape and length as the
     * random draw it replaced — 4 digits for a registration, 6 for a licence —
     * so no test sees an identifier of a different form than before.
     */
    protected static function uniqueSeq(int $width = 4): string
    {
        static $n = 0;

        return str_pad((string) ++$n, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Give a vendor the approved onboarding a working vendor actually has.
     *
     * Onboarding is the gate on everything a vendor does — registering workers,
     * every operational write in either portal — and "approved" means an
     * onboarding record whose status says so, NOT a status column reading
     * Active. The two disagree often enough that reading the column was the bug.
     *
     * Most fixtures predate that rule: they build a vendor with status Active,
     * no onboarding at all, and go straight to the thing they actually mean to
     * test (a medical, a competency, a badge). That shortcut now describes a
     * vendor that could not exist — nothing gets a workforce without onboarding
     * first — so this puts the missing record in and keeps each fixture honest
     * about what it is assuming.
     *
     * Call it for a vendor that is meant to be up and running. Do NOT call it in
     * a test about the gate itself; those build the state they mean explicitly.
     */
    protected function markOnboarded(object $vendor): object
    {
        $tenantId = $vendor->tenant_id;

        if ($vendor instanceof \App\Models\Purchase\PurchaseVendor) {
            \App\Models\Purchase\PurchaseOnboarding::firstOrCreate(
                ['tenant_id' => $tenantId, 'purchase_vendor_id' => $vendor->id],
                ['status' => 'Approved', 'current_step' => 6],
            );
        } elseif ($vendor instanceof \App\Models\Vendor\Vendor) {
            \App\Models\Tpv\TpvOnboarding::firstOrCreate(
                ['tenant_id' => $tenantId, 'vendor_id' => $vendor->id],
                ['status' => 'Approved', 'current_step' => 6],
            );
        }

        return $vendor->fresh();
    }
}
