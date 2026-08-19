<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\PermitJsaStep;
use App\Models\Tpv\WorkPermit;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Support\Facades\Log;

/**
 * Permit-to-Work lifecycle (Doc_4 Phase 5). Governance enforced here:
 *
 *  - A permit may not be APPROVED without at least one JSA step (no permit
 *    without a hazard assessment).
 *  - A permit may not be approved for a suspended/inactive vendor — the same
 *    compliance gate the badge and the site obey.
 *  - A permit ACTIVATES only from Approved, and closes/expires cleanly.
 */
class PermitService
{
    public function create(int $tenantId, array $data, ?User $actor): WorkPermit
    {
        $permit = WorkPermit::create([
            'tenant_id'    => $tenantId,
            'reference'    => $this->nextReference($tenantId),
            'vendor_id'    => $data['vendor_id'] ?? null,
            'requested_by' => $actor?->id,
            'type'         => $data['type'],
            'title'        => $data['title'],
            'location'     => $data['location'] ?? null,
            'description'  => $data['description'] ?? null,
            'hazards'      => $data['hazards'] ?? null,
            'precautions'  => $data['precautions'] ?? null,
            'valid_from'   => $data['valid_from'] ?? null,
            'valid_to'     => $data['valid_to'] ?? null,
            'status'       => 'Requested',
        ]);

        $permit->recordAudit('Permit Requested', $actor, null, ['reference' => $permit->reference, 'type' => $permit->type]);

        return $permit->fresh(['jsaSteps']);
    }

    public function addJsaStep(WorkPermit $permit, array $data, ?User $actor): PermitJsaStep
    {
        $nextNo = (int) $permit->jsaSteps()->max('step_no') + 1;

        return $permit->jsaSteps()->create([
            'tenant_id'     => $permit->tenant_id,
            'step_no'       => $data['step_no'] ?? $nextNo,
            'activity'      => $data['activity'],
            'hazard'        => $data['hazard'] ?? null,
            'control'       => $data['control'] ?? null,
            'residual_risk' => $data['residual_risk'] ?? 'Low',
        ]);
    }

    public function approve(WorkPermit $permit, ?User $actor, ?string $remarks = null): WorkPermit
    {
        if ($permit->status !== 'Requested') {
            throw new BusinessException('Only a requested permit can be approved.', 422);
        }
        if ($permit->jsaSteps()->count() === 0) {
            throw new BusinessException('A Job Safety Analysis (at least one step) is required before approval.', 422);
        }
        if ($permit->vendor_id) {
            $vendor = Vendor::find($permit->vendor_id);
            if ($vendor && $vendor->status !== VendorStatus::ACTIVE) {
                throw new BusinessException("Cannot approve — vendor is {$vendor->status_label}.", 422);
            }
        }

        $permit->update([
            'status'           => 'Approved',
            'approved_by'      => $actor?->id,
            'approved_at'      => now(),
            'decision_remarks' => $remarks,
        ]);
        $permit->recordAudit('Permit Approved', $actor, $remarks, ['reference' => $permit->reference]);

        return $permit->fresh(['jsaSteps']);
    }

    public function reject(WorkPermit $permit, ?User $actor, string $remarks): WorkPermit
    {
        if (! in_array($permit->status, ['Requested', 'Approved'], true)) {
            throw new BusinessException('This permit cannot be rejected.', 422);
        }
        $permit->update(['status' => 'Rejected', 'decision_remarks' => $remarks, 'approved_by' => $actor?->id, 'approved_at' => now()]);
        $permit->recordAudit('Permit Rejected', $actor, $remarks, ['reference' => $permit->reference]);

        return $permit->fresh(['jsaSteps']);
    }

    public function activate(WorkPermit $permit, ?User $actor): WorkPermit
    {
        if ($permit->status !== 'Approved') {
            throw new BusinessException('Only an approved permit can be made active.', 422);
        }
        if ($permit->is_expired) {
            throw new BusinessException('This permit has passed its validity window.', 422);
        }
        $permit->update(['status' => 'Active']);
        $permit->recordAudit('Permit Activated', $actor, null, ['reference' => $permit->reference]);

        return $permit->fresh(['jsaSteps']);
    }

    public function close(WorkPermit $permit, ?User $actor): WorkPermit
    {
        if (in_array($permit->status, ['Closed', 'Rejected'], true)) {
            return $permit;
        }
        $permit->update(['status' => 'Closed', 'closed_at' => now(), 'closed_by' => $actor?->id]);
        $permit->recordAudit('Permit Closed', $actor, null, ['reference' => $permit->reference]);

        return $permit->fresh(['jsaSteps']);
    }

    /** Expire permits whose window has passed (nightly sweep). Returns the count. */
    public function expireLapsed(?int $tenantId = null): int
    {
        $q = WorkPermit::whereIn('status', ['Requested', 'Approved', 'Active'])
            ->whereNotNull('valid_to')
            ->where('valid_to', '<', now())
            ->when($tenantId, fn ($x) => $x->where('tenant_id', $tenantId));

        $n = 0;
        $q->chunkById(200, function ($batch) use (&$n) {
            foreach ($batch as $permit) {
                $permit->update(['status' => 'Expired']);
                $n++;
            }
        });
        if ($n) {
            Log::channel('tpv')->info('Work permits expired', ['count' => $n]);
        }

        return $n;
    }

    private function nextReference(int $tenantId): string
    {
        $year = now()->format('Y');
        $n = WorkPermit::where('tenant_id', $tenantId)->where('reference', 'like', "PTW-{$year}-%")->count() + 1;

        return sprintf('PTW-%s-%03d', $year, $n);
    }
}
