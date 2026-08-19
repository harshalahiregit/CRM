<?php

namespace App\Services\Tpv;

use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Vendor\VendorScorecardService;
use App\Support\Tpv\TpvWorkerStatus;
use App\Support\Vendor\VendorStatus;

/**
 * HSSE governance dashboard (Doc 6) — one command view over everything the
 * program produces: incidents, vendor ratings, statutory-document currency,
 * safety strikes and workforce readiness. Read-only aggregation; the numbers are
 * derived live so the board is never stale.
 */
class GovernanceDashboardService
{
    public function __construct(private VendorScorecardService $vrs)
    {
    }

    public function build(int $tenantId): array
    {
        return [
            'incidents' => $this->incidents($tenantId),
            'vendors'   => $this->vendors($tenantId),
            'ratings'   => $this->ratings($tenantId),
            'workforce' => $this->workforce($tenantId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function incidents(int $tenantId): array
    {
        $base = HsseIncident::where('tenant_id', $tenantId);
        $open = (clone $base)->where('status', '!=', 'Closed');

        return [
            'total'          => (clone $base)->count(),
            'open'           => (clone $open)->count(),
            'open_by_severity' => [
                'Fatal'    => (clone $open)->where('severity', 'Fatal')->count(),
                'Serious'  => (clone $open)->where('severity', 'Serious')->count(),
                'Moderate' => (clone $open)->where('severity', 'Moderate')->count(),
                'Minor'    => (clone $open)->where('severity', 'Minor')->count(),
            ],
            'stop_works'     => (clone $base)->where('stop_work', true)->where('status', '!=', 'Closed')->count(),
            'recent'         => (clone $base)->with('vendor:id,company_name')
                ->latest('occurred_at')->limit(5)
                ->get(['id', 'reference', 'title', 'severity', 'status', 'vendor_id', 'occurred_at']),
        ];
    }

    private function vendors(int $tenantId): array
    {
        $base = Vendor::forTenant($tenantId);
        $active = (clone $base)->where('status', VendorStatus::ACTIVE)->count();
        $suspended = (clone $base)->where('status', VendorStatus::SUSPENDED)->count();

        // Statutory documents lapsing within 30 days (renewal watch).
        $expiringDocs = VendorDocument::where('tenant_id', $tenantId)
            ->where('status', 'Approved')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();

        return [
            'total'         => (clone $base)->count(),
            'active'        => $active,
            'suspended'     => $suspended,
            'expiring_docs' => $expiringDocs,
        ];
    }

    private function ratings(int $tenantId): array
    {
        $bands = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $sum = 0;
        $n = 0;

        // Live-rated across active vendors (bounded); the board reflects "now".
        Vendor::forTenant($tenantId)
            ->where('status', VendorStatus::ACTIVE)
            ->chunkById(200, function ($batch) use (&$bands, &$sum, &$n) {
                foreach ($batch as $vendor) {
                    $card = $this->vrs->compute($vendor);
                    $bands[$card['band']] = ($bands[$card['band']] ?? 0) + 1;
                    $sum += $card['overall_score'];
                    $n++;
                }
            });

        return [
            'rated'      => $n,
            'avg_score'  => $n ? (int) round($sum / $n) : null,
            'bands'      => $bands,
        ];
    }

    private function workforce(int $tenantId): array
    {
        $activeWorkers = TpvWorker::forTenant($tenantId)->where('status', TpvWorkerStatus::ACTIVE)->count();

        $activeStrikes = TpvSafetyStrike::forTenant($tenantId)->whereNull('voided_at')->count();

        // Medical certificates lapsing within 30 days across the workforce.
        $medicalExpiring = TpvWorkerMedical::forTenant($tenantId)
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();

        return [
            'active_workers'   => $activeWorkers,
            'active_strikes'   => $activeStrikes,
            'medical_expiring' => $medicalExpiring,
        ];
    }
}
