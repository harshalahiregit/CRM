<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseContract;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseRenewal;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorViolation;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Purchase\PurchaseViolationType;

/**
 * Purchase Renewal & Extension — the Purchase-side mirror of TpvRenewalService
 * (parity rule). Assesses a vendor from its Performance Index + open governance
 * items and applies the renewal decision. Purchase has no VRS, so the assessment
 * draws on PurchaseVendorPerformanceService (VPI).
 */
class PurchaseRenewalService
{
    public function __construct(
        private PurchaseVendorPerformanceService $vpi,
        private PurchaseVendorService $vendors,
    ) {}

    public function list(int $tenantId, array $filters = [])
    {
        return PurchaseRenewal::forTenant($tenantId)
            ->with('vendor:id,company_name,purchase_vendor_code,status')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->latest('id')
            ->get();
    }

    /** Pull the renewal-assessment snapshot for a vendor. */
    public function assess(PurchaseVendor $vendor): array
    {
        $card = $this->vpi->compute($vendor);
        $tenantId = $vendor->tenant_id;

        $violPoints = (int) PurchaseVendorViolation::forTenant($tenantId)->where('purchase_vendor_id', $vendor->id)
            ->where('status', 'Open')->sum('points');

        return [
            'vpi_score' => $card['overall_score'] ?? null,
            'vpi_band' => $card['band'] ?? null,
            'open_ncrs' => PurchaseNcr::forTenant($tenantId)->where('purchase_vendor_id', $vendor->id)->where('status', '!=', 'Closed')->count(),
            'open_capas' => PurchaseCapa::forTenant($tenantId)->where('purchase_vendor_id', $vendor->id)->where('status', '!=', 'Verified')->count(),
            'violation_points' => $violPoints,
            'violation_level' => PurchaseViolationType::levelFor($violPoints),
            'vendor_status' => $vendor->status,
            'assessed_at' => now()->toIso8601String(),
        ];
    }

    public function initiate(array $data, int $tenantId, int $userId): PurchaseRenewal
    {
        $vendor = PurchaseVendor::forTenant($tenantId)->findOrFail($data['purchase_vendor_id']);

        return PurchaseRenewal::create([
            'tenant_id' => $tenantId,
            'purchase_vendor_id' => $vendor->id,
            'contract_id' => $data['contract_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'assessment' => $this->assess($vendor),
            'status' => 'Assessed',
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ])->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    public function reassess(PurchaseRenewal $renewal): PurchaseRenewal
    {
        $renewal->update(['assessment' => $this->assess($renewal->vendor), 'status' => 'Assessed']);

        return $renewal->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    /**
     * Record + APPLY the renewal decision. Extend/Renew push a contract's end
     * date; Suspend routes through PurchaseVendorService (On_Hold). Other
     * outcomes are recorded for the audit trail.
     */
    public function decide(PurchaseRenewal $renewal, array $data, User $actor): PurchaseRenewal
    {
        $decision = $data['decision'];

        $renewal->update([
            'decision' => $decision,
            'conditions' => $data['conditions'] ?? null,
            'new_end_date' => $data['new_end_date'] ?? null,
            'status' => 'Decided',
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        if (in_array($decision, ['Renew', 'Renew_With_Conditions', 'Extend'], true)
            && ! empty($data['new_end_date']) && $renewal->contract_id) {
            PurchaseContract::where('tenant_id', $renewal->tenant_id)->where('id', $renewal->contract_id)
                ->update(['end_date' => $data['new_end_date']]);
        }

        if ($decision === 'Suspend' && $renewal->vendor) {
            $this->vendors->updateStatus($renewal->vendor, PurchaseVendorStatus::ON_HOLD, $actor, $data['conditions'] ?? 'On hold at renewal review');
        }

        $renewal->recordAudit('Renewal '.$decision, $actor, $data['conditions'] ?? null, ['decision' => $decision]);

        return $renewal->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    public function delete(PurchaseRenewal $renewal): void
    {
        $renewal->delete();
    }
}
