<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase central approval register (Sangoe TPV §12) — a generic, additive
 * register: raise an approval of one of the ~18 types, then an admin decides it.
 * Purchase-owned mirror of TpvApprovalService: no routing, no authority-level
 * logic, no delegation, no activation side-effects (those live, where they exist,
 * in the Purchase onboarding stage chain). Touches only
 * purchase_approval_requests.
 */
class PurchaseApprovalRequestService
{
    /** Tenant-scoped, optionally filtered listing for the register. */
    public function list(int $tenantId, array $filters = [])
    {
        return PurchaseApprovalRequest::forTenant($tenantId)
            ->with([
                'vendor:id,company_name,purchase_vendor_code',
                'requester:id,name', 'decider:id,name',
            ])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['approval_type'] ?? null, fn ($q, $t) => $q->where('approval_type', $t))
            ->when($filters['purchase_vendor_id'] ?? null, fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->latest('id')
            ->get();
    }

    /** Raise a new Pending approval entry. */
    public function raise(array $data, int $tenantId, int $userId): PurchaseApprovalRequest
    {
        $approval = PurchaseApprovalRequest::create([
            'tenant_id'          => $tenantId,
            'approval_type'      => $data['approval_type'],
            'subject_type'       => $data['subject_type'] ?? null,
            'subject_id'         => $data['subject_id'] ?? null,
            'purchase_vendor_id' => $data['purchase_vendor_id'] ?? null,
            'title'              => $data['title'],
            'description'        => $data['description'] ?? null,
            'priority'           => $data['priority'] ?? 'Medium',
            'status'             => 'Pending',
            'requested_by'       => $userId,
            'meta'               => $data['meta'] ?? null,
        ]);

        $approval->recordAudit('Approval Requested', null, null, [
            'reference' => $approval->reference, 'approval_type' => $approval->approval_type,
        ]);
        Log::channel('purchase')->info('Purchase approval raised', [
            'approval_id' => $approval->id, 'tenant_id' => $tenantId, 'reference' => $approval->reference,
        ]);

        return $approval->load('vendor:id,company_name,purchase_vendor_code', 'requester:id,name');
    }

    /**
     * Decide a pending approval. Only Pending entries are mutable; a rejection
     * needs remarks. Mirrors TpvApprovalService::decide — no side-effects.
     */
    public function decide(PurchaseApprovalRequest $approval, string $decision, ?string $remarks, User $actor): PurchaseApprovalRequest
    {
        if ($approval->status !== 'Pending') {
            throw new BusinessException('This approval is already '.$approval->status.'.');
        }

        $map = ['approve' => 'Approved', 'reject' => 'Rejected', 'cancel' => 'Cancelled'];
        if (! isset($map[$decision])) {
            throw new BusinessException('Unknown approval decision.');
        }
        if ($decision === 'reject' && trim((string) $remarks) === '') {
            throw new BusinessException('A rejection needs a reason.');
        }

        $status = $map[$decision];
        $approval->update([
            'status'           => $status,
            'decided_by'       => $actor->id,
            'decided_at'       => now(),
            'decision_remarks' => $remarks,
        ]);

        $approval->recordAudit('Approval '.$status, $actor, $remarks, ['reference' => $approval->reference]);
        Log::channel('purchase')->info('Purchase approval decided', [
            'approval_id' => $approval->id, 'status' => $status, 'actor_id' => $actor->id,
        ]);

        return $approval->load('vendor:id,company_name,purchase_vendor_code', 'requester:id,name', 'decider:id,name');
    }
}
