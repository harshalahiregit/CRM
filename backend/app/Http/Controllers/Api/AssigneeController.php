<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who work can be assigned to.
 *
 * Screens that assign an owner had no way to ask this: the only user list is
 * /api/admin/staff, which is behind role:admin, so a salesperson creating a
 * proposal could not read it. The Proposals screen worked around that with a
 * HARDCODED array of five names posted as a string, which the backend — wanting
 * a users id — discarded on every save.
 *
 * Deliberately minimal: id and name, active staff and admins in the caller's own
 * tenant. Nothing here is sensitive enough to need a permission of its own, and
 * anything richer belongs in Staff Management.
 */
class AssigneeController extends Controller
{
    public function index(Request $request)
    {
        $rows = User::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('role', ['staff', 'admin'])
            // Somebody deactivated should not appear as a choice — assigning work
            // to an account that cannot sign in is a quiet way to lose it.
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department']);

        return response()->json([
            'status' => 'success',
            'data'   => $rows->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => (string) $u->name,
                'email'      => (string) $u->email,
                'department' => (string) ($u->department ?? ''),
            ])->values(),
        ]);
    }
}
