<?php

namespace App\Console\Commands;

use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseSettingService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Console\Command;

/**
 * One-off backfill for Purchase Vendors that were activated before the access
 * window was introduced, so their countdown has an end date.
 *
 * Purchase-owned: touches purchase_vendors only, and reads the validity period
 * from Purchase Settings. TPV has its own separate command.
 *
 * Conservative by design — it only fills a NULL expiry on an Active, Temporary
 * vendor that has an approved_at to count from. An existing expiry is never
 * overwritten, and a Standard vendor is never given one. Safe to re-run.
 */
class BackfillPurchaseVendorExpiry extends Command
{
    protected $signature = 'purchase:backfill-vendor-expiry {--dry-run : List what would change without writing}';

    protected $description = 'Set access_expires_at on activated Temporary Purchase Vendors that never got an access window';

    public function handle(PurchaseSettingService $settings): int
    {
        $dry = (bool) $this->option('dry-run');

        $candidates = PurchaseVendor::query()
            ->where('status', PurchaseVendorStatus::ACTIVE)
            ->whereNull('access_expires_at')
            ->whereNotNull('approved_at')
            ->get();

        // isTemporary() is the model's own rule (registration_type, with the
        // legacy vendor_type as fallback) — not re-derived here.
        $targets = $candidates->filter(fn (PurchaseVendor $v) => $v->isTemporary());

        $this->info(sprintf(
            'Purchase: %d active vendor(s) without an expiry; %d are Temporary and will be backfilled.',
            $candidates->count(), $targets->count(),
        ));

        if ($targets->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($targets as $vendor) {
            $days = (int) $settings->get($vendor->tenant_id, 'temporary_vendor_validity_days');
            $days = $days > 0 ? $days : 5;
            $expires = $vendor->approved_at->copy()->addDays($days);

            $rows[] = [$vendor->purchase_vendor_code, $vendor->company_name, $vendor->approved_at->toDateTimeString(), $days, $expires->toDateTimeString()];

            if (! $dry) {
                $vendor->forceFill(['access_expires_at' => $expires])->saveQuietly();
            }
        }

        $this->table(['Code', 'Company', 'Approved At', 'Days', 'Expires At'], $rows);
        $this->info($dry ? 'Dry run — nothing written.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
