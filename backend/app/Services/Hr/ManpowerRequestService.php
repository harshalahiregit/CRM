<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\HrApprovalHistory;
use App\Models\HrManpowerRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManpowerRequestService
{
    public function list(User $user, array $filters): Collection
    {
        $query = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->with(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'approvalHistory.actor']);

        if ($user->isHiringManager()) {
            $query->where('assigned_manager_id', $user->id);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }

        return $query->latest()->get();
    }

    public function pendingApprovals(User $user): array
    {
        $role = $user->internal_role ?? $user->role;

        $l1Pending = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->where('status', 'Pending_L1')
            ->with(['requester'])
            ->latest()->get();

        $l2Pending = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->where('status', 'Pending_L2')
            ->with(['requester', 'l1Approver'])
            ->latest()->get();

        return [
            'l1_pending' => $user->isAdmin() || in_array($role, ['hiring_manager', 'department_head']) ? $l1Pending : [],
            'l2_pending' => $user->isAdmin() ? $l2Pending : [],
            'l1_count'   => $l1Pending->count(),
            'l2_count'   => $l2Pending->count(),
        ];
    }

    public function create(array $data, User $user): HrManpowerRequest
    {
        DB::beginTransaction();
        try {
            $mr = HrManpowerRequest::create([
                ...$data,
                'status'       => 'Draft',
                'l1_status'    => 'pending',
                'l2_status'    => 'pending',
                'requested_by' => $user->id,
                'tenant_id'    => $user->tenant_id,
            ]);

            HrApprovalHistory::create([
                'request_id' => $mr->id,
                'level'      => 'General',
                'action'     => 'Created',
                'actor_id'   => $user->id,
                'remarks'    => 'Manpower request created',
                'new_values' => json_encode($data),
            ]);

            DB::commit();

            Log::channel('hr')->info('Manpower request created', ['request_id' => $mr->id, 'tenant_id' => $user->tenant_id]);

            return $mr->load(['requester', 'assignedManager']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException('Failed to create: '.$e->getMessage(), 500);
        }
    }

    public function submit(HrManpowerRequest $manpowerRequest, User $user): HrManpowerRequest
    {
        if ($manpowerRequest->status !== 'Draft') {
            Log::channel('hr')->warning('Manpower submit rejected: not Draft', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new BusinessException('Only Draft requests can be submitted', 422);
        }

        if ($manpowerRequest->requested_by !== $user->id && ! $user->isAdmin()) {
            Log::channel('hr')->warning('Manpower submit rejected: not requester/admin', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new BusinessException('Only the requester can submit this request', 403);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update(['status' => 'Pending_L1', 'l1_status' => 'pending']);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L1',
                'action'     => 'Submitted',
                'actor_id'   => $user->id,
                'remarks'    => 'Submitted for Department Head (L1) approval',
                'old_values' => json_encode(['status' => 'Draft']),
                'new_values' => json_encode(['status' => 'Pending_L1']),
            ]);

            DB::commit();

            Log::channel('hr')->info('Manpower request submitted for L1', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);

            return $manpowerRequest->fresh()->load(['requester', 'approvalHistory.actor']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException('Failed: '.$e->getMessage(), 500);
        }
    }

    public function approveL1(HrManpowerRequest $manpowerRequest, User $user, ?string $remarks): HrManpowerRequest
    {
        if ($manpowerRequest->status !== 'Pending_L1') {
            Log::channel('hr')->warning('L1 approval rejected: not Pending_L1', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new BusinessException('Request is not pending L1 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'         => 'Pending_L2',
                'l1_status'      => 'approved',
                'l1_approver_id' => $user->id,
                'l1_approved_at' => now(),
                'l1_remarks'     => $remarks,
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L1',
                'action'     => 'Approved',
                'actor_id'   => $user->id,
                'remarks'    => $remarks ?? 'L1 Approved by Department Head',
                'old_values' => json_encode(['status' => 'Pending_L1']),
                'new_values' => json_encode(['status' => 'Pending_L2', 'l1_status' => 'approved']),
            ]);

            DB::commit();

            Log::channel('hr')->info('Manpower request L1 approved', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'approver_id' => $user->id]);

            return $manpowerRequest->fresh()->load(['requester', 'l1Approver', 'approvalHistory.actor']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException('Failed: '.$e->getMessage(), 500);
        }
    }

    public function rejectL1(HrManpowerRequest $manpowerRequest, User $user, string $remarks): HrManpowerRequest
    {
        if ($manpowerRequest->status !== 'Pending_L1') {
            Log::channel('hr')->warning('L1 rejection rejected: not Pending_L1', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new BusinessException('Request is not pending L1 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'            => 'Rejected',
                'l1_status'         => 'rejected',
                'l1_approver_id'    => $user->id,
                'l1_approved_at'    => now(),
                'l1_remarks'        => $remarks,
                'rejection_reason'  => '[L1] '.$remarks,
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L1',
                'action'     => 'Rejected',
                'actor_id'   => $user->id,
                'remarks'    => $remarks,
                'old_values' => json_encode(['status' => 'Pending_L1']),
                'new_values' => json_encode(['status' => 'Rejected', 'l1_status' => 'rejected']),
            ]);

            DB::commit();

            Log::channel('hr')->info('Manpower request rejected at L1', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'approver_id' => $user->id]);

            return $manpowerRequest->fresh()->load(['requester', 'approvalHistory.actor']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException('Failed: '.$e->getMessage(), 500);
        }
    }

    public function approveL2(HrManpowerRequest $manpowerRequest, User $user, ?string $remarks): HrManpowerRequest
    {
        if ($manpowerRequest->status !== 'Pending_L2') {
            Log::channel('hr')->warning('L2 approval rejected: not Pending_L2', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new BusinessException('Request is not pending L2 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'         => 'Approved',
                'l2_status'      => 'approved',
                'l2_approver_id' => $user->id,
                'l2_approved_at' => now(),
                'l2_remarks'     => $remarks,
                'approved_by'    => $user->id,
                'approved_at'    => now(),
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L2',
                'action'     => 'Approved',
                'actor_id'   => $user->id,
                'remarks'    => $remarks ?? 'L2 Approved by Management — HR can now post the job',
                'old_values' => json_encode(['status' => 'Pending_L2']),
                'new_values' => json_encode(['status' => 'Approved', 'l2_status' => 'approved']),
            ]);

            DB::commit();

            Log::channel('hr')->info('Manpower request L2 approved (fully approved)', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'approver_id' => $user->id]);

            return $manpowerRequest->fresh()->load(['requester', 'l1Approver', 'l2Approver', 'approvalHistory.actor']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException('Failed: '.$e->getMessage(), 500);
        }
    }

    public function rejectL2(HrManpowerRequest $manpowerRequest, User $user, string $remarks): HrManpowerRequest
    {
        if ($manpowerRequest->status !== 'Pending_L2') {
            Log::channel('hr')->warning('L2 rejection rejected: not Pending_L2', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new BusinessException('Request is not pending L2 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'           => 'Rejected',
                'l2_status'        => 'rejected',
                'l2_approver_id'   => $user->id,
                'l2_approved_at'   => now(),
                'l2_remarks'       => $remarks,
                'rejection_reason' => '[L2] '.$remarks,
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L2',
                'action'     => 'Rejected',
                'actor_id'   => $user->id,
                'remarks'    => $remarks,
                'old_values' => json_encode(['status' => 'Pending_L2']),
                'new_values' => json_encode(['status' => 'Rejected', 'l2_status' => 'rejected']),
            ]);

            DB::commit();

            Log::channel('hr')->info('Manpower request rejected at L2', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'approver_id' => $user->id]);

            return $manpowerRequest->fresh()->load(['requester', 'approvalHistory.actor']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException('Failed: '.$e->getMessage(), 500);
        }
    }

    public function update(HrManpowerRequest $manpowerRequest, array $data): HrManpowerRequest
    {
        if (! in_array($manpowerRequest->status, ['Draft', 'Rejected'])) {
            Log::channel('hr')->warning('Manpower update rejected: not Draft/Rejected', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new BusinessException('Only Draft or Rejected requests can be edited', 422);
        }

        $manpowerRequest->update($data);

        Log::channel('hr')->info('Manpower request updated', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);

        return $manpowerRequest->fresh()->load(['requester']);
    }

    public function destroy(HrManpowerRequest $manpowerRequest, User $user): void
    {
        if ($manpowerRequest->requested_by !== $user->id && ! $user->isAdmin()) {
            Log::channel('hr')->warning('Manpower delete rejected: unauthorized', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
            throw new UnauthorizedTenantException('Unauthorized');
        }

        $manpowerRequest->delete();

        Log::channel('hr')->info('Manpower request deleted', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'      => HrManpowerRequest::where('tenant_id', $tenantId)->count(),
            'draft'      => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Draft')->count(),
            'pending_l1' => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Pending_L1')->count(),
            'pending_l2' => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Pending_L2')->count(),
            'approved'   => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Approved')->count(),
            'rejected'   => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Rejected')->count(),
        ];
    }

    public function pendingCount(int $tenantId): int
    {
        return HrManpowerRequest::where('tenant_id', $tenantId)
            ->whereIn('status', ['Pending_L1', 'Pending_L2'])
            ->count();
    }

    public function assignManager(HrManpowerRequest $manpowerRequest, int $managerId): HrManpowerRequest
    {
        $manager = User::findOrFail($managerId);
        $manpowerRequest->update(['assigned_manager_id' => $manager->id]);

        Log::channel('hr')->info('Manpower request manager assigned', ['request_id' => $manpowerRequest->id, 'tenant_id' => $manpowerRequest->tenant_id, 'manager_id' => $manager->id]);

        return $manpowerRequest->fresh()->load('assignedManager');
    }
}
