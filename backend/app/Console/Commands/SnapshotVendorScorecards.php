<?php

namespace App\Console\Commands;

use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorScorecardService;
use Illuminate\Console\Command;

/**
 * Monthly VRS snapshot (Doc 5). Persists every vendor's scorecard for the period
 * so the trend over time is visible and auditable. The scorecard itself is always
 * available live; this is the historical record.
 */
class SnapshotVendorScorecards extends Command
{
    protected $signature = 'vrs:snapshot {--tenant= : Only this tenant} {--period= : YYYY-MM (default: this month)}';

    protected $description = 'Persist a monthly Vendor Rating System scorecard for every vendor';

    public function handle(VendorScorecardService $vrs): int
    {
        $period = $this->option('period');
        $tenant = $this->option('tenant');
        $count = 0;

        Vendor::query()
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->chunkById(200, function ($batch) use ($vrs, $period, &$count) {
                foreach ($batch as $vendor) {
                    $card = $vrs->snapshot($vendor, $period);
                    $count++;
                    $this->line("#{$vendor->id} {$vendor->company_name}: {$card->overall_score} ({$card->band})");
                }
            });

        $this->info("Snapshotted {$count} vendor scorecard(s) for ".($period ?: now()->format('Y-m')).'.');

        return self::SUCCESS;
    }
}
