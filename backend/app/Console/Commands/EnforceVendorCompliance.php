<?php

namespace App\Console\Commands;

use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Vendor\VendorService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Nightly vendor compliance sweep (Doc_2 / Doc_4 — auto-suspend on lapsed cover).
 *
 * An active vendor whose statutory cover has expired (a required, previously
 * approved document past its expiry) is auto-SUSPENDED — which locks their login
 * and, being a non-Active status, withholds site access at the gate and blocks
 * new badge issues (TpvWorkerService::blockers checks vendor Active). When the
 * document is renewed the same sweep auto-REINSTATES them. Only system
 * suspensions (auto_suspended) are auto-lifted; an admin's manual suspension is
 * never touched.
 *
 * Idempotent: a vendor already in the right state is skipped, so it is safe to
 * run nightly (or catch up after downtime) without side effects.
 */
class EnforceVendorCompliance extends Command
{
    protected $signature = 'vendors:enforce-compliance {--tenant= : Only this tenant} {--dry-run : Report without changing anything}';

    protected $description = 'Auto-suspend vendors whose statutory documents have expired, and reinstate them when renewed';

    public function handle(VendorService $vendors): int
    {
        $dry = (bool) $this->option('dry-run');
        $tenant = $this->option('tenant');

        $suspended = 0;
        $reinstated = 0;

        // 1. Active vendors that have fallen out of compliance → suspend.
        Vendor::query()
            ->where('status', VendorStatus::ACTIVE)
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->with('documents')
            ->chunkById(200, function ($batch) use ($vendors, $dry, &$suspended) {
                foreach ($batch as $vendor) {
                    $expired = $this->expiredRequiredDocs($vendor);
                    if ($expired->isEmpty()) {
                        continue;
                    }

                    $reason = 'Auto-suspended — expired statutory document(s): '
                        .$expired->map(fn ($d) => VendorDocument::typeLabel($d->type)
                            .' (expired '.optional($d->expires_at)->format('d M Y').')')->implode(', ');

                    $this->warn("Suspend #{$vendor->id} {$vendor->company_name}: {$reason}");
                    if (! $dry) {
                        $vendors->suspend($vendor, $reason, null, true);
                    }
                    $suspended++;
                }
            });

        // 2. System-suspended vendors that are compliant again → reinstate.
        Vendor::query()
            ->where('status', VendorStatus::SUSPENDED)
            ->where('auto_suspended', true)
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->with('documents')
            ->chunkById(200, function ($batch) use ($vendors, $dry, &$reinstated) {
                foreach ($batch as $vendor) {
                    if ($this->expiredRequiredDocs($vendor)->isNotEmpty()) {
                        continue;
                    }

                    $this->info("Reinstate #{$vendor->id} {$vendor->company_name}: compliance restored.");
                    if (! $dry) {
                        $vendors->reinstate($vendor);
                    }
                    $reinstated++;
                }
            });

        $this->line(($dry ? '[dry-run] ' : '')."Done — suspended {$suspended}, reinstated {$reinstated}.");

        return self::SUCCESS;
    }

    /**
     * Required, previously-approved documents that are now past their expiry — the
     * lapsed statutory cover that triggers suspension. A required doc with no
     * expiry set never expires; a missing one is an onboarding gate, not a
     * suspension trigger (the vendor was activated with them present).
     */
    private function expiredRequiredDocs(Vendor $vendor): Collection
    {
        $required = VendorDocument::requiredFor($vendor->vendor_type ?? 'standard');

        return $vendor->documents
            ->filter(fn (VendorDocument $d) => $d->isApproved()
                && in_array($d->type, $required, true)
                && $d->expires_at !== null
                && $d->expires_at->isPast())
            ->values();
    }
}
