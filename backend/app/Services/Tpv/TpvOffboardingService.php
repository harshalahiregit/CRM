<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvOffboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorService;
use App\Support\Vendor\VendorStatus;

/**
 * TPV Offboarding / Closure (Sangoe TPV §29). A controlled exit checklist that,
 * on completion, applies the final vendor status through the shared VendorService
 * (which already terminates workers, revokes badges and locks the login).
 */
class TpvOffboardingService
{
    public function __construct(private VendorService $vendors) {}

    public function list(int $tenantId, array $filters = [])
    {
        return TpvOffboarding::forTenant($tenantId)
            ->with('vendor:id,company_name,vendor_code,status')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->get();
    }

    public function detail(TpvOffboarding $offboarding): TpvOffboarding
    {
        return $offboarding->load('vendor:id,company_name,vendor_code,status');
    }

    /** Start an offboarding for a vendor (one open at a time). */
    public function initiate(array $data, int $tenantId, int $userId): TpvOffboarding
    {
        $vendor = Vendor::forTenant($tenantId)->findOrFail($data['vendor_id']);

        $existing = TpvOffboarding::forTenant($tenantId)->where('vendor_id', $vendor->id)
            ->where('status', 'In_Progress')->first();
        if ($existing) {
            throw new BusinessException('An offboarding is already in progress for this vendor.');
        }

        return TpvOffboarding::create([
            'tenant_id' => $tenantId,
            'vendor_id' => $vendor->id,
            'reason' => $data['reason'] ?? null,
            'checklist' => TpvOffboarding::defaultChecklist(),
            'status' => 'In_Progress',
            'created_by' => $userId,
        ])->load('vendor:id,company_name,vendor_code,status');
    }

    public function updateChecklist(TpvOffboarding $offboarding, array $checklist): TpvOffboarding
    {
        $offboarding->update(['checklist' => $checklist]);

        return $offboarding->load('vendor:id,company_name,vendor_code,status');
    }

    /**
     * Complete the offboarding — all checklist items must be done first. Applies
     * the chosen final status via the shared VendorService.
     */
    public function complete(TpvOffboarding $offboarding, array $data, User $actor): TpvOffboarding
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
            match ($final) {
                'Closed', 'Replaced' => $this->vendors->offboard($vendor, $reason, $actor),
                'Suspended' => $this->vendors->suspend($vendor, $reason, $actor),
                'Blacklisted' => $this->vendors->updateStatus($vendor, VendorStatus::BLACKLISTED, $actor, $reason),
                default => throw new BusinessException("Unknown final status: {$final}."),
            };
        }

        $offboarding->update([
            'status' => 'Completed',
            'final_status' => $final,
            'completed_at' => now(),
            'completed_by' => $actor->id,
            'lessons_learned' => $data['lessons_learned'] ?? $offboarding->lessons_learned,
        ]);

        $offboarding->recordAudit('Offboarding Completed', $actor, $reason, ['final_status' => $final]);

        return $offboarding->load('vendor:id,company_name,vendor_code,status');
    }

    public function delete(TpvOffboarding $offboarding): void
    {
        $offboarding->delete();
    }
}
