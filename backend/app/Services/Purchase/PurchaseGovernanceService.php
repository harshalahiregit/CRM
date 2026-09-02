<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseGateScan;
use App\Models\Purchase\PurchaseHsseIncident;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorkPermit;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase's governance dashboard.
 *
 * This exists rather than reusing TPV's GovernanceDashboardService because that
 * one reads hsse_incidents, vendors, vendor_documents, tpv_workers and
 * tpv_worker_medicals — every single figure on it is a TPV figure. Showing those
 * under a Purchase menu would be worse than showing nothing: a procurement lead
 * would read "3 open incidents" and act on a number that has nothing to do with
 * their vendors. Labelling it would not fix that; the fix is to count Purchase's
 * own registers.
 *
 * Deliberately NOT mirrored from TPV:
 *  - safety strikes, which Purchase has no table for. An always-zero card reads
 *    as "no strikes" rather than "not tracked here".
 *  - vendor ratings, which come from VendorScorecardService over the shared
 *    vendors table. Purchase scores vendors through its own VPI instead.
 *
 * Shared site registers (observations, toolbox talks, drills, evidence) are also
 * left out: they are genuinely shared with TPV, and folding them in would make
 * this dashboard look like a Purchase-only picture when half of it is not.
 * Those have their own pages.
 */
class PurchaseGovernanceService
{
    public function build(int $tenantId): array
    {
        return [
            'incidents'    => $this->incidents($tenantId),
            'vendors'      => $this->vendors($tenantId),
            'workforce'    => $this->workforce($tenantId),
            'permits'      => $this->permits($tenantId),
            'gate'         => $this->gate($tenantId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function incidents(int $tenantId): array
    {
        // Guarded: the incident table is a newer addition and a deployment that
        // has not migrated should get a quiet zero, not a 500 on the dashboard.
        if (! Schema::hasTable('purchase_hsse_incidents')) {
            return ['total' => 0, 'open' => 0, 'stop_works' => 0, 'recent' => [], 'available' => false];
        }

        $base = fn () => PurchaseHsseIncident::where('tenant_id', $tenantId);
        $open = fn () => $base()->where('status', '!=', 'Closed');

        return [
            'available'  => true,
            'total'      => $base()->count(),
            'open'       => $open()->count(),
            'stop_works' => Schema::hasColumn('purchase_hsse_incidents', 'stop_work')
                ? $open()->where('stop_work', true)->count()
                : 0,
            'recent'     => $base()->latest('id')->limit(5)->get(),
        ];
    }

    private function vendors(int $tenantId): array
    {
        $base = fn () => PurchaseVendor::where('tenant_id', $tenantId);

        $expiring = 0;
        if (Schema::hasTable('purchase_documents') && Schema::hasColumn('purchase_documents', 'expiry_date')) {
            $expiring = \DB::table('purchase_documents')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count();
        }

        return [
            'total'         => $base()->count(),
            'active'        => $base()->where('status', 'Active')->count(),
            'suspended'     => $base()->whereIn('status', ['Suspended', 'Blacklisted'])->count(),
            'expiring_docs' => $expiring,
        ];
    }

    private function workforce(int $tenantId): array
    {
        $medicalExpiring = PurchaseWorkerMedical::where('tenant_id', $tenantId)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();

        return [
            'active_workers'   => PurchaseWorker::forTenant($tenantId)->where('status', 'Active')->count(),
            'badged'           => PurchaseWorker::forTenant($tenantId)->whereNotNull('badge_number')->count(),
            'medical_expiring' => $medicalExpiring,
        ];
    }

    private function permits(int $tenantId): array
    {
        $base = fn () => PurchaseWorkPermit::where('tenant_id', $tenantId);

        return [
            'requested' => $base()->where('status', 'Requested')->count(),
            'active'    => $base()->where('status', 'Active')->count(),
            // Live permits already past their window — the queue someone has to
            // clear, and the reason is_expired is derived rather than stored.
            'lapsing'   => $base()->whereIn('status', ['Approved', 'Active'])
                ->whereNotNull('valid_to')
                ->whereDate('valid_to', '<', now()->toDateString())
                ->count(),
        ];
    }

    private function gate(int $tenantId): array
    {
        $today = now()->toDateString();
        $base = fn () => PurchaseGateScan::forTenant($tenantId)->whereDate('scanned_at', $today);

        return [
            'scans_today'  => $base()->count(),
            'denied_today' => $base()->where('decision', PurchaseGateScan::DENY)->count(),
        ];
    }
}
