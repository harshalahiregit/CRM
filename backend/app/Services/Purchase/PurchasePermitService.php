<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchasePermitJsaStep;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorkPermit;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Permit To Work — mirror of TPV's PermitService on Purchase's tables.
 *
 * The lifecycle is Requested → Approved → Active → Closed, with Rejected and
 * Expired as terminal branches. Every transition is guarded rather than merely
 * offered: a permit is the document that says dangerous work may proceed, so
 * "the button was hidden" is not a control.
 *
 * Two guards carry the weight, both mirrored from TPV:
 *  - approval requires a JSA with at least one step. A permit approved with no
 *    hazard analysis is the failure mode the whole form exists to prevent.
 *  - approval refuses a vendor who is not Active, because permitting work to a
 *    suspended vendor routes around the suspension entirely.
 */
class PurchasePermitService
{
    public function list(int $tenantId, array $filters = [])
    {
        $q = PurchaseWorkPermit::where('tenant_id', $tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'jsaSteps']);

        foreach (['status', 'type'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }
        if (! empty($filters['vendor_id'])) {
            $q->where('purchase_vendor_id', (int) $filters['vendor_id']);
        }

        return $q->latest('id')->get();
    }

    public function create(int $tenantId, array $data, ?User $actor): PurchaseWorkPermit
    {
        $permit = PurchaseWorkPermit::create(array_merge($data, [
            'tenant_id'    => $tenantId,
            'status'       => 'Requested',
            'requested_by' => $actor?->id,
        ]));

        Log::channel('purchase')->info('Purchase permit requested', [
            'permit_id' => $permit->id, 'reference' => $permit->reference,
        ]);

        return $permit->fresh(['jsaSteps', 'vendor']);
    }

    /**
     * Add a JSA step. Numbered on append rather than supplied, so two people
     * filling the analysis cannot both claim step 3.
     */
    public function addJsaStep(PurchaseWorkPermit $permit, array $data): PurchasePermitJsaStep
    {
        if (in_array($permit->status, ['Closed', 'Rejected', 'Expired'], true)) {
            throw new BusinessException('This permit is closed — its JSA can no longer be changed.', 422);
        }

        return PurchasePermitJsaStep::create([
            'tenant_id'     => $permit->tenant_id,
            'permit_id'     => $permit->id,
            'step_no'       => (int) $permit->jsaSteps()->max('step_no') + 1,
            'activity'      => $data['activity'],
            'hazard'        => $data['hazard'] ?? null,
            'control'       => $data['control'] ?? null,
            'residual_risk' => $data['residual_risk'] ?? null,
        ]);
    }

    public function approve(PurchaseWorkPermit $permit, ?User $actor, ?string $remarks = null): PurchaseWorkPermit
    {
        if ($permit->status !== 'Requested') {
            throw new BusinessException('Only a requested permit can be approved.', 422);
        }
        // A permit approved with no hazard analysis is the exact failure the
        // form exists to prevent.
        if ($permit->jsaSteps()->count() === 0) {
            throw new BusinessException('A Job Safety Analysis (at least one step) is required before approval.', 422);
        }
        if ($permit->purchase_vendor_id) {
            $vendor = PurchaseVendor::find($permit->purchase_vendor_id);
            // Permitting work to a suspended vendor would route around the
            // suspension entirely.
            if ($vendor && $vendor->status !== 'Active') {
                throw new BusinessException("Cannot approve — vendor is {$vendor->status}.", 422);
            }
        }

        $permit->update([
            'status'           => 'Approved',
            'approved_by'      => $actor?->id,
            'approved_at'      => now(),
            'decision_remarks' => $remarks,
        ]);

        return $permit->fresh(['jsaSteps', 'vendor']);
    }

    public function reject(PurchaseWorkPermit $permit, ?User $actor, string $remarks): PurchaseWorkPermit
    {
        if ($permit->status !== 'Requested') {
            throw new BusinessException('Only a requested permit can be rejected.', 422);
        }

        $permit->update([
            'status'           => 'Rejected',
            'approved_by'      => $actor?->id,
            'approved_at'      => now(),
            'decision_remarks' => $remarks,
        ]);

        return $permit->fresh(['jsaSteps', 'vendor']);
    }

    public function activate(PurchaseWorkPermit $permit, ?User $actor): PurchaseWorkPermit
    {
        if ($permit->status !== 'Approved') {
            throw new BusinessException('Only an approved permit can be made active.', 422);
        }
        if ($permit->is_expired) {
            throw new BusinessException('This permit has passed its validity window.', 422);
        }

        $permit->update(['status' => 'Active']);

        return $permit->fresh(['jsaSteps', 'vendor']);
    }

    public function close(PurchaseWorkPermit $permit, ?User $actor): PurchaseWorkPermit
    {
        if (! in_array($permit->status, ['Approved', 'Active'], true)) {
            throw new BusinessException('Only an approved or active permit can be closed.', 422);
        }

        $permit->update([
            'status'    => 'Closed',
            'closed_at' => now(),
            'closed_by' => $actor?->id,
        ]);

        return $permit->fresh(['jsaSteps', 'vendor']);
    }

    /**
     * Lapse permits whose window has passed.
     *
     * Only Approved/Active are expired: a Requested permit that was never
     * decided is a decision someone still owes, and quietly expiring it would
     * bury that.
     */
    public function expireLapsed(?int $tenantId = null): int
    {
        $q = PurchaseWorkPermit::whereIn('status', ['Approved', 'Active'])
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '<', now()->toDateString());

        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }

        return $q->update(['status' => 'Expired']);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseWorkPermit::where('tenant_id', $tenantId);

        return [
            'total'     => $base()->count(),
            'requested' => $base()->where('status', 'Requested')->count(),
            'approved'  => $base()->where('status', 'Approved')->count(),
            'active'    => $base()->where('status', 'Active')->count(),
            'closed'    => $base()->where('status', 'Closed')->count(),
            'rejected'  => $base()->where('status', 'Rejected')->count(),
            'expired'   => $base()->where('status', 'Expired')->count(),
        ];
    }
}
