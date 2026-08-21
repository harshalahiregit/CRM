<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvApproval;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Central TPV approval engine (Sangoe TPV §12). Raise, list and decide generic
 * approval requests. Other services can call raise() to route an action through
 * approval; the onboarding chain stays independent.
 */
class TpvApprovalService
{
    public function list(int $tenantId, array $filters = [])
    {
        return TpvApproval::forTenant($tenantId)
            ->with(['vendor:id,company_name,vendor_code', 'requester:id,name', 'decider:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['approval_type'] ?? null, fn ($q, $t) => $q->where('approval_type', $t))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->latest('id')
            ->get();
    }

    /**
     * Raise an approval request. `$subject` optionally links the model being
     * approved (its morph type/id + vendor_id are captured when resolvable).
     */
    public function raise(array $data, int $tenantId, int $userId): TpvApproval
    {
        $approval = TpvApproval::create([
            'tenant_id' => $tenantId,
            'approval_type' => $data['approval_type'],
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'Medium',
            'status' => 'Pending',
            'requested_by' => $userId,
            'meta' => $data['meta'] ?? null,
        ]);

        Log::channel('tpv')->info('TPV approval raised', [
            'approval_id' => $approval->id, 'tenant_id' => $tenantId, 'type' => $approval->approval_type,
        ]);

        return $approval->load('vendor:id,company_name,vendor_code', 'requester:id,name');
    }

    /** Approve / reject / cancel. Terminal states cannot be re-decided. */
    public function decide(TpvApproval $approval, string $decision, ?string $remarks, User $actor): TpvApproval
    {
        if ($approval->status !== 'Pending') {
            throw new BusinessException("This approval is already {$approval->status}.");
        }

        $map = ['approve' => 'Approved', 'reject' => 'Rejected', 'cancel' => 'Cancelled'];
        if (! isset($map[$decision])) {
            throw new BusinessException("Unknown decision: {$decision}.");
        }
        if ($decision === 'reject' && empty($remarks)) {
            throw new BusinessException('A reason is required to reject an approval.');
        }

        $approval->update([
            'status' => $map[$decision],
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_remarks' => $remarks,
        ]);

        $approval->recordAudit('Approval '.$map[$decision], $actor, $remarks, [
            'type' => $approval->approval_type,
        ]);

        return $approval->load('vendor:id,company_name,vendor_code', 'requester:id,name', 'decider:id,name');
    }
}
