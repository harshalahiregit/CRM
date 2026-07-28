<?php

namespace App\Console\Commands;

use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvAccessService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Console\Command;

/**
 * One-off backfill for Third-Party Vendors that were activated before the
 * access window was introduced, so their countdown has an end date.
 *
 * TPV-owned: touches vendors only, and takes the validity period from the
 * record's own validity_days (falling back to the TPV access service's
 * default). Purchase has its own separate command.
 *
 * Conservative by design — it only fills a NULL expiry on an Active, temporary
 * vendor with a start point to count from (access_start_at, else approved_at).
 * An existing expiry is never overwritten, and a Long-Term TPV is never given
 * one. Safe to re-run.
 */
class BackfillTpvVendorExpiry extends Command
{
    protected $signature = 'tpv:backfill-vendor-expiry {--dry-run : List what would change without writing}';

    protected $description = 'Set access_expires_at on activated Temporary TPVs that never got an access window';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $candidates = Vendor::query()
            ->where('status', VendorStatus::ACTIVE)
            ->whereNull('access_expires_at')
            ->get();

        $targets = $candidates->filter(
            // isTemporary() is the model's own rule; a start point is required
            // so the window is never counted from the registration date.
            fn (Vendor $v) => $v->isTemporary() && ($v->access_start_at || $v->approved_at)
        );

        $skipped = $candidates->filter(fn (Vendor $v) => $v->isTemporary() && ! $v->access_start_at && ! $v->approved_at);

        $this->info(sprintf(
            'TPV: %d active vendor(s) without an expiry; %d temporary will be backfilled; %d skipped (no activation timestamp).',
            $candidates->count(), $targets->count(), $skipped->count(),
        ));

        if ($targets->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($targets as $vendor) {
            $start = $vendor->access_start_at ?: $vendor->approved_at;
            $days  = (int) ($vendor->validity_days ?: 0);
            $days  = $days > 0 ? $days : TpvAccessService::DEFAULT_VALIDITY_DAYS;
            $expires = $start->copy()->addDays($days);

            $rows[] = [$vendor->vendor_code, $vendor->company_name, $start->toDateTimeString(), $days, $expires->toDateTimeString()];

            if (! $dry) {
                $vendor->forceFill([
                    'access_start_at'   => $start,
                    'access_expires_at' => $expires,
                    'validity_days'     => $days,
                ])->saveQuietly();
            }
        }

        $this->table(['Code', 'Company', 'Start', 'Days', 'Expires At'], $rows);
        $this->info($dry ? 'Dry run — nothing written.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
