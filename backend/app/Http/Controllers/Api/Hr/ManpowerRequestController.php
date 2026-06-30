<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrManpowerRequest;
use App\Models\HrApprovalHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManpowerRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->with(['requester', 'assignedManager', 'approver']);

        // Filter based on role
        if ($user->role === 'hiring_manager') {
            // Hiring managers see only requests assigned to them
            $query->where('assigned_manager_id', $user->id);
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        if ($request->filled('department') && $request->department !== 'All') {
            $query->where('department', $request->department);
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'department'    => 'required|string|max:100',
            'position_title'=> 'required|string|max:200',
            'number_of_posts'=> 'required|integer|min:1',
            'priority'      => 'required|in:Low,Medium,High',
            'job_type'      => 'required|in:Full-time,Part-time,Contract,Internship',
            'required_by_date' => 'nullable|date',
            'justification' => 'nullable|string',
            'assigned_manager_id' => 'nullable|exists:users,id',
        ]);

        DB::beginTransaction();
        try {
            // Auto-assign manager if not provided
            if (empty($validated['assigned_manager_id'])) {
                $manager = User::where('role', 'hiring_manager')
                               ->where('tenant_id', auth()->user()->tenant_id)
                               ->first();
                
                if ($manager) {
                    $validated['assigned_manager_id'] = $manager->id;
                }
            }

            $mr = HrManpowerRequest::create([
                ...$validated,
                'status'       => 'Pending',
                'requested_by' => auth()->id(),
                'tenant_id'    => auth()->user()->tenant_id,
            ]);

            // Log creation
            HrApprovalHistory::logAction(
                $mr,
                'Created',
                'Manpower request created',
                null,
                $validated
            );

            // TODO: Send notification to assigned manager
            // Notification::send($manager, new ManpowerRequestCreated($mr));

            DB::commit();

            return response()->json($mr->load(['requester', 'assignedManager']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(HrManpowerRequest $manpowerRequest)
    {
        return response()->json(
            $manpowerRequest->load(['requester', 'assignedManager', 'approver', 'approvalHistory.user'])
        );
    }

    public function updateStatus(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $user = $request->user();

        // Check if user can approve this request
        if (!$manpowerRequest->canBeApprovedBy($user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to approve this request',
            ], 403);
        }

        $request->validate([
            'status'           => 'required|in:Approved,Rejected,Pending',
            'rejection_reason' => 'required_if:status,Rejected|nullable|string|max:500',
            'comment'          => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $oldValues = [
                'status' => $manpowerRequest->status,
                'rejection_reason' => $manpowerRequest->rejection_reason,
            ];

            $data = ['status' => $request->status];

            if ($request->status === 'Rejected') {
                $data['rejection_reason'] = $request->rejection_reason;
            }

            if ($request->status === 'Approved') {
                $data['approved_by'] = $user->id;
                $data['approved_at'] = now();
            }

            $manpowerRequest->update($data);

            // Log the action
            HrApprovalHistory::logAction(
                $manpowerRequest,
                $request->status === 'Approved' ? 'Approved' : 'Rejected',
                $request->comment,
                $oldValues,
                $data
            );

            // TODO: Send notification to requester
            // Notification::send($manpowerRequest->requester, new RequestStatusChanged($manpowerRequest));

            DB::commit();

            return response()->json(
                $manpowerRequest->fresh()->load(['requester', 'assignedManager', 'approver', 'approvalHistory.user'])
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(HrManpowerRequest $manpowerRequest)
    {
        // Only requester or admin can delete
        $user = auth()->user();
        if ($manpowerRequest->requested_by !== $user->id && !$user->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to delete this request',
            ], 403);
        }

        $manpowerRequest->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * Get pending approvals count for current manager
     */
    public function pendingCount(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'hiring_manager' && !$user->isAdmin()) {
            return response()->json(['count' => 0]);
        }

        $count = HrManpowerRequest::pendingForManager($user->id)->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Assign/reassign manager to a request
     */
    public function assignManager(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $request->validate([
            'manager_id' => 'required|exists:users,id',
        ]);

        $manager = User::findOrFail($request->manager_id);

        if ($manager->role !== 'hiring_manager' && !$manager->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Selected user is not a hiring manager',
            ], 422);
        }

        $oldManagerId = $manpowerRequest->assigned_manager_id;

        $manpowerRequest->update([
            'assigned_manager_id' => $manager->id,
            'manager_notified_at' => now(),
        ]);

        // Log the assignment
        HrApprovalHistory::logAction(
            $manpowerRequest,
            'Assigned',
            'Request assigned to ' . $manager->name,
            ['assigned_manager_id' => $oldManagerId],
            ['assigned_manager_id' => $manager->id]
        );

        return response()->json($manpowerRequest->fresh()->load('assignedManager'));
    }
}
