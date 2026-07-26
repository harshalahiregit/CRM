<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Assignable people in the current tenant — the source for "who do I assign this
 * to?" pickers (ticket assignee, convert-to-tasks).
 *
 * Exists because /admin/staff is admin-only, so a regular agent could not fill an
 * assignee dropdown and was left typing raw user ids. This is read-only, returns
 * nothing but id/name/email/role, and is tenant-scoped.
 */
class HelpdeskAgentController extends Controller
{
    use ApiResponse;

    /** Roles that are external to the company — never ticket assignees. */
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    public function index(Request $request)
    {
        $agents = User::where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'active')
            ->whereNotIn('role', self::EXTERNAL_ROLES)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return $this->success($agents, 'Agents retrieved');
    }
}
