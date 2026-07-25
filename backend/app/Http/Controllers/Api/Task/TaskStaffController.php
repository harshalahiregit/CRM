<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Assignable people in the current tenant — the source for the assignee/follower
 * pickers. The Task module owns this rather than borrowing Helpdesk's /agents
 * endpoint, so neither module depends on the other's routes.
 *
 * Read-only, tenant-scoped, returns nothing but id/name/email/role.
 */
class TaskStaffController extends Controller
{
    use ApiResponse;

    /** Portal-only roles — never task assignees (mirrors TaskService). */
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    public function index(Request $request)
    {
        $staff = User::where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'active')
            ->whereNotIn('role', self::EXTERNAL_ROLES)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return $this->success($staff, 'Staff retrieved');
    }
}
