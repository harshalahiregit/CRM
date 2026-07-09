<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\HrManpowerRequest;
use App\Models\HrApprovalHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManpowerRequestController extends Controller
{
    use ApiResponse;


    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower
     ───────────────────────────────────────────── */
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->with(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'approvalHistory.actor']);

        // Hiring managers only see their own
        if ($user->isHiringManager()) {
            $query->where('assigned_manager_id', $user->id);
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        if ($request->filled('department') && $request->department !== 'All') {
            $query->where('department', $request->department);
        }

        return $this->success($query->latest()->get());
    }

    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower/pending-approvals
     ───────────────────────────────────────────── */
    public function pendingApprovals(Request $request)
    {
        $user = $request->user();
        $role = $user->internal_role ?? $user->role;

        // L1 pending — Department Head / Hiring Manager
        $l1Pending = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->where('status', 'Pending_L1')
            ->with(['requester'])
            ->latest()->get();

        // L2 pending — Admin / Management
        $l2Pending = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->where('status', 'Pending_L2')
            ->with(['requester', 'l1Approver'])
            ->latest()->get();

        return $this->success([
            'l1_pending' => $user->isAdmin() || in_array($role, ['hiring_manager', 'department_head']) ? $l1Pending : [],
            'l2_pending' => $user->isAdmin() ? $l2Pending : [],
            'l1_count'   => $l1Pending->count(),
            'l2_count'   => $l2Pending->count(),
        ]);
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower
     ───────────────────────────────────────────── */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'department'         => 'required|string|max:100',
            'position_title'     => 'required|string|max:200',
            'number_of_posts'    => 'required|integer|min:1',
            'priority'           => 'required|in:Low,Medium,High',
            'job_type'           => 'required|in:Full-time,Part-time,Contract,Internship',
            'required_by_date'   => 'nullable|date',
            'justification'      => 'nullable|string',
            'assigned_manager_id'=> 'nullable|exists:users,id',
        ]);

        DB::beginTransaction();
        try {
            $mr = HrManpowerRequest::create([
                ...$validated,
                'status'       => 'Draft',
                'l1_status'    => 'pending',
                'l2_status'    => 'pending',
                'requested_by' => auth()->id(),
                'tenant_id'    => auth()->user()->tenant_id,
            ]);

            HrApprovalHistory::create([
                'request_id' => $mr->id,
                'level'      => 'General',
                'action'     => 'Created',
                'actor_id'   => auth()->id(),
                'remarks'    => 'Manpower request created',
                'new_values' => json_encode($validated),
            ]);

            DB::commit();
            return $this->success($mr->load(['requester', 'assignedManager']), 'Request created', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create: ' . $e->getMessage(), 500);
        }
    }

    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower/{id}
     ───────────────────────────────────────────── */
    public function show(HrManpowerRequest $manpowerRequest)
    {
        return $this->success(
            $manpowerRequest->load(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'approvalHistory.actor'])
        );
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/submit
     | Submit for L1 review (by requester)
     ───────────────────────────────────────────── */
    public function submit(Request $request, HrManpowerRequest $manpowerRequest)
    {
        if ($manpowerRequest->status !== 'Draft') {
            return $this->error('Only Draft requests can be submitted', 422);
        }

        if ($manpowerRequest->requested_by !== auth()->id() && !auth()->user()->isAdmin()) {
            return $this->error('Only the requester can submit this request', 403);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update(['status' => 'Pending_L1', 'l1_status' => 'pending']);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L1',
                'action'     => 'Submitted',
                'actor_id'   => auth()->id(),
                'remarks'    => 'Submitted for Department Head (L1) approval',
                'old_values' => json_encode(['status' => 'Draft']),
                'new_values' => json_encode(['status' => 'Pending_L1']),
            ]);

            DB::commit();
            return $this->success($manpowerRequest->fresh()->load(['requester', 'approvalHistory.actor']), 'Submitted for L1 approval');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed: ' . $e->getMessage(), 500);
        }
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/approve-l1
     | L1 — Department Head approves
     ───────────────────────────────────────────── */
    public function approveL1(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $request->validate(['remarks' => 'nullable|string|max:500']);

        if ($manpowerRequest->status !== 'Pending_L1') {
            return $this->error('Request is not pending L1 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'          => 'Pending_L2',
                'l1_status'       => 'approved',
                'l1_approver_id'  => auth()->id(),
                'l1_approved_at'  => now(),
                'l1_remarks'      => $request->remarks,
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L1',
                'action'     => 'Approved',
                'actor_id'   => auth()->id(),
                'remarks'    => $request->remarks ?? 'L1 Approved by Department Head',
                'old_values' => json_encode(['status' => 'Pending_L1']),
                'new_values' => json_encode(['status' => 'Pending_L2', 'l1_status' => 'approved']),
            ]);

            DB::commit();
            return $this->success($manpowerRequest->fresh()->load(['requester', 'l1Approver', 'approvalHistory.actor']), 'L1 Approved — now pending Management (L2) approval');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed: ' . $e->getMessage(), 500);
        }
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/reject-l1
     ───────────────────────────────────────────── */
    public function rejectL1(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $request->validate(['remarks' => 'required|string|max:500']);

        if ($manpowerRequest->status !== 'Pending_L1') {
            return $this->error('Request is not pending L1 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'         => 'Rejected',
                'l1_status'      => 'rejected',
                'l1_approver_id' => auth()->id(),
                'l1_approved_at' => now(),
                'l1_remarks'     => $request->remarks,
                'rejection_reason' => '[L1] ' . $request->remarks,
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L1',
                'action'     => 'Rejected',
                'actor_id'   => auth()->id(),
                'remarks'    => $request->remarks,
                'old_values' => json_encode(['status' => 'Pending_L1']),
                'new_values' => json_encode(['status' => 'Rejected', 'l1_status' => 'rejected']),
            ]);

            DB::commit();
            return $this->success($manpowerRequest->fresh()->load(['requester', 'approvalHistory.actor']), 'Request rejected at L1');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed: ' . $e->getMessage(), 500);
        }
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/approve-l2
     | L2 — Management approves → HR can now post job
     ───────────────────────────────────────────── */
    public function approveL2(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $request->validate(['remarks' => 'nullable|string|max:500']);

        if ($manpowerRequest->status !== 'Pending_L2') {
            return $this->error('Request is not pending L2 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'         => 'Approved',
                'l2_status'      => 'approved',
                'l2_approver_id' => auth()->id(),
                'l2_approved_at' => now(),
                'l2_remarks'     => $request->remarks,
                'approved_by'    => auth()->id(),
                'approved_at'    => now(),
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L2',
                'action'     => 'Approved',
                'actor_id'   => auth()->id(),
                'remarks'    => $request->remarks ?? 'L2 Approved by Management — HR can now post the job',
                'old_values' => json_encode(['status' => 'Pending_L2']),
                'new_values' => json_encode(['status' => 'Approved', 'l2_status' => 'approved']),
            ]);

            DB::commit();
            return $this->success($manpowerRequest->fresh()->load(['requester', 'l1Approver', 'l2Approver', 'approvalHistory.actor']), '✅ Fully Approved — HR can now post the job');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed: ' . $e->getMessage(), 500);
        }
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/reject-l2
     ───────────────────────────────────────────── */
    public function rejectL2(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $request->validate(['remarks' => 'required|string|max:500']);

        if ($manpowerRequest->status !== 'Pending_L2') {
            return $this->error('Request is not pending L2 approval', 422);
        }

        DB::beginTransaction();
        try {
            $manpowerRequest->update([
                'status'           => 'Rejected',
                'l2_status'        => 'rejected',
                'l2_approver_id'   => auth()->id(),
                'l2_approved_at'   => now(),
                'l2_remarks'       => $request->remarks,
                'rejection_reason' => '[L2] ' . $request->remarks,
            ]);

            HrApprovalHistory::create([
                'request_id' => $manpowerRequest->id,
                'level'      => 'L2',
                'action'     => 'Rejected',
                'actor_id'   => auth()->id(),
                'remarks'    => $request->remarks,
                'old_values' => json_encode(['status' => 'Pending_L2']),
                'new_values' => json_encode(['status' => 'Rejected', 'l2_status' => 'rejected']),
            ]);

            DB::commit();
            return $this->success($manpowerRequest->fresh()->load(['requester', 'approvalHistory.actor']), 'Request rejected at L2');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed: ' . $e->getMessage(), 500);
        }
    }

    /* ─────────────────────────────────────────────
     | PUT /api/hr/manpower/{id}
     ───────────────────────────────────────────── */
    public function update(Request $request, HrManpowerRequest $manpowerRequest)
    {
        if (!in_array($manpowerRequest->status, ['Draft', 'Rejected'])) {
            return $this->error('Only Draft or Rejected requests can be edited', 422);
        }

        $validated = $request->validate([
            'department'       => 'sometimes|string|max:100',
            'position_title'   => 'sometimes|string|max:200',
            'number_of_posts'  => 'sometimes|integer|min:1',
            'priority'         => 'sometimes|in:Low,Medium,High',
            'job_type'         => 'sometimes|in:Full-time,Part-time,Contract,Internship',
            'required_by_date' => 'nullable|date',
            'justification'    => 'nullable|string',
        ]);

        $manpowerRequest->update($validated);
        return $this->success($manpowerRequest->fresh()->load(['requester']), 'Updated successfully');
    }

    /* ─────────────────────────────────────────────
     | DELETE /api/hr/manpower/{id}
     ───────────────────────────────────────────── */
    public function destroy(HrManpowerRequest $manpowerRequest)
    {
        $user = auth()->user();
        if ($manpowerRequest->requested_by !== $user->id && !$user->isAdmin()) {
            return $this->error('Unauthorized', 403);
        }
        $manpowerRequest->delete();
        return $this->success(null, 'Deleted successfully');
    }

    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower/stats
     ───────────────────────────────────────────── */
    public function stats(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        return $this->success([
            'total'      => HrManpowerRequest::where('tenant_id', $tenantId)->count(),
            'draft'      => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Draft')->count(),
            'pending_l1' => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Pending_L1')->count(),
            'pending_l2' => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Pending_L2')->count(),
            'approved'   => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Approved')->count(),
            'rejected'   => HrManpowerRequest::where('tenant_id', $tenantId)->where('status', 'Rejected')->count(),
        ]);
    }

    /* ─────────────────────────────────────────────
     | Legacy: pendingCount + assignManager
     ───────────────────────────────────────────── */
    public function pendingCount(Request $request)
    {
        $user  = $request->user();
        $count = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->whereIn('status', ['Pending_L1', 'Pending_L2'])
            ->count();
        return $this->success(['count' => $count]);
    }

    public function assignManager(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $request->validate(['manager_id' => 'required|exists:users,id']);
        $manager = User::findOrFail($request->manager_id);
        $manpowerRequest->update(['assigned_manager_id' => $manager->id]);
        return $this->success($manpowerRequest->fresh()->load('assignedManager'), 'Manager assigned');
    }
}
