<?php

namespace App\Services\Tpv;

use App\Models\Tpv\IncidentCapa;
use App\Models\Tpv\TpvContract;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\TpvRenewal;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorScorecardService;
use App\Services\Vendor\VendorService;
use App\Support\Tpv\ViolationType;

/**
 * TPV Renewal & Extension (Sangoe TPV §28). Assesses a vendor from its
 * performance + open governance items (Rule 10) and applies the renewal decision.
 */
class TpvRenewalService
{
    public function __construct(
        private VendorScorecardService $vrs,
        private VendorService $vendors,
    ) {}

    public function list(int $tenantId, array $filters = [])
    {
        return TpvRenewal::forTenant($tenantId)
            ->with('vendor:id,company_name,vendor_code,status')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->latest('id')
            ->get();
    }

    /** Pull the renewal-assessment snapshot for a vendor (Rule 10 inputs). */
    public function assess(Vendor $vendor): array
    {
        $card = $this->vrs->compute($vendor);
        $tenantId = $vendor->tenant_id;

        $violPoints = (int) TpvVendorViolation::forTenant($tenantId)->where('vendor_id', $vendor->id)
            ->where('status', 'Open')->sum('points');

        return [
            'vrs_score' => $card['overall_score'] ?? null,
            'vrs_band' => $card['band'] ?? null,
            'open_ncrs' => TpvNcr::forTenant($tenantId)->where('vendor_id', $vendor->id)->where('status', '!=', 'Closed')->count(),
            'open_capas' => IncidentCapa::where('tenant_id', $tenantId)->whereNotIn('status', ['Done', 'Verified'])
                ->whereHas('incident', fn ($q) => $q->where('vendor_id', $vendor->id))->count(),
            'active_strikes' => TpvSafetyStrike::forTenant($tenantId)->active()
                ->whereHas('worker', fn ($q) => $q->where('vendor_id', $vendor->id))->count(),
            'violation_points' => $violPoints,
            'violation_level' => ViolationType::levelFor($violPoints),
            'vendor_status' => $vendor->status,
            'assessed_at' => now()->toIso8601String(),
        ];
    }

    public function initiate(array $data, int $tenantId, int $userId): TpvRenewal
    {
        $vendor = Vendor::forTenant($tenantId)->findOrFail($data['vendor_id']);

        return TpvRenewal::create([
            'tenant_id' => $tenantId,
            'vendor_id' => $vendor->id,
            'contract_id' => $data['contract_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'assessment' => $this->assess($vendor),
            'status' => 'Assessed',
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ])->load('vendor:id,company_name,vendor_code,status');
    }

    /** Refresh the assessment snapshot from live data. */
    public function reassess(TpvRenewal $renewal): TpvRenewal
    {
        $renewal->update(['assessment' => $this->assess($renewal->vendor), 'status' => 'Assessed']);

        return $renewal->load('vendor:id,company_name,vendor_code,status');
    }

    /**
     * Record + APPLY the renewal decision. Extend/Renew push a contract's end
     * date; Suspend routes through VendorService. Other outcomes are recorded
     * for the record (the admin acts on them via the vendor workspace).
     */
    public function decide(TpvRenewal $renewal, array $data, User $actor): TpvRenewal
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
            TpvContract::where('tenant_id', $renewal->tenant_id)->where('id', $renewal->contract_id)
                ->update(['end_date' => $data['new_end_date'], 'status' => 'Renewed']);
        }

        if ($decision === 'Suspend' && $renewal->vendor) {
            $this->vendors->suspend($renewal->vendor, $data['conditions'] ?? 'Suspended at renewal review', $actor);
        }

        $renewal->recordAudit('Renewal '.$decision, $actor, $data['conditions'] ?? null, ['decision' => $decision]);

        return $renewal->load('vendor:id,company_name,vendor_code,status');
    }

    public function delete(TpvRenewal $renewal): void
    {
        $renewal->delete();
    }
}
