<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseOffboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorStatus;

/**
 * Purchase Offboarding / Closure — the Purchase-side mirror of
 * TpvOffboardingService (parity rule). A controlled exit checklist that, on
 * completion, applies the final vendor status through PurchaseVendorService.
 * Purchase has no dedicated Offboarded/Suspended states, so Closed/Replaced map
 * to Inactive and Suspended maps to On_Hold.
 */
class PurchaseOffboardingService
{
    public function __construct(private PurchaseVendorService $vendors) {}

    public function list(int $tenantId, array $filters = [])
    {
        return PurchaseOffboarding::forTenant($tenantId)
            ->with('vendor:id,company_name,purchase_vendor_code,status')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->get();
    }

    public function detail(PurchaseOffboarding $offboarding): PurchaseOffboarding
    {
        return $offboarding->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    /** Start an offboarding for a vendor (one open at a time). */
    public function initiate(array $data, int $tenantId, int $userId): PurchaseOffboarding
    {
        $vendor = PurchaseVendor::forTenant($tenantId)->findOrFail($data['purchase_vendor_id']);

        $existing = PurchaseOffboarding::forTenant($tenantId)->where('purchase_vendor_id', $vendor->id)
            ->where('status', 'In_Progress')->first();
        if ($existing) {
            throw new BusinessException('An offboarding is already in progress for this vendor.');
        }

        return PurchaseOffboarding::create([
            'tenant_id' => $tenantId,
            'purchase_vendor_id' => $vendor->id,
            'reason' => $data['reason'] ?? null,
            'checklist' => PurchaseOffboarding::defaultChecklist(),
            'status' => 'In_Progress',
            'created_by' => $userId,
        ])->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    public function updateChecklist(PurchaseOffboarding $offboarding, array $checklist): PurchaseOffboarding
    {
        $offboarding->update(['checklist' => $checklist]);

        return $offboarding->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    /**
     * Complete the offboarding — all checklist items must be done first. Applies
     * the chosen final status via the shared PurchaseVendorService.
     */
    public function complete(PurchaseOffboarding $offboarding, array $data, User $actor): PurchaseOffboarding
    {
        if ($offboarding->status === 'Completed') {
            throw new BusinessException('This offboarding is already completed.');
        }
        $pending = collect($offboarding->checklist ?? [])->where('done', false)->count();
        if ($pending > 0) {
            throw new BusinessException("Complete all {$pending} remaining checklist item(s) before closing the offboarding.");
        }

        $final = $data['final_status'];
        $vendor = $offboarding->vendor;
        $reason = $data['reason'] ?? $offboarding->reason ?? 'Vendor offboarded';

        if ($vendor) {
            $status = match ($final) {
                'Closed', 'Replaced' => PurchaseVendorStatus::INACTIVE,
                'Suspended' => PurchaseVendorStatus::ON_HOLD,
                'Blacklisted' => PurchaseVendorStatus::BLACKLISTED,
                default => throw new BusinessException("Unknown final status: {$final}."),
            };
            $this->vendors->updateStatus($vendor, $status, $actor, $reason);
        }

        $offboarding->update([
            'status' => 'Completed',
            'final_status' => $final,
            'completed_at' => now(),
            'completed_by' => $actor->id,
            'lessons_learned' => $data['lessons_learned'] ?? $offboarding->lessons_learned,
        ]);

        $offboarding->recordAudit('Offboarding Completed', $actor, $reason, ['final_status' => $final]);

        return $offboarding->load('vendor:id,company_name,purchase_vendor_code,status');
    }

    public function delete(PurchaseOffboarding $offboarding): void
    {
        $offboarding->delete();
    }
}
