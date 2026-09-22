<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Access\Department;
use App\Services\Access\AccessCatalogService;
use Illuminate\Http\Request;

/**
 * Departments, maintained from Settings.
 *
 * Admin-only, and tenant-scoped on every read and write — a department is
 * looked up THROUGH the tenant, so an id from another workspace resolves to
 * nothing rather than to someone else's record.
 *
 * ROLES WERE REMOVED FROM HERE. This class used to maintain a second staff-role
 * catalogue in access_roles, alongside the live one in staff_roles. Two tables
 * wrote the same column (users.internal_role) and only one of them carried
 * permissions, so the other could never be the authority — it was a role
 * manager that granted nothing. staff_roles owns the vocabulary now, and the
 * two names this path uniquely defined — `hr` and `manager`, which
 * routes/sangoetrack.php gates on — are vocabulary-only rows in
 * StaffRoleTemplate. The access_roles table is untouched.
 *
 * The departments half is the SAME duplication one table over
 * (access_departments, 0 rows, versus hr_departments, read by 21 files) and is
 * deliberately left alone: it is its own cleanup with its own blast radius.
 */
class AccessCatalogController extends Controller
{
    public function __construct(private AccessCatalogService $catalog) {}

    /* ── Departments ────────────────────────────────────────────────────── */

    public function departments(Request $request)
    {
        return response()->json(['data' => $this->catalog->departments($request->user()->tenant_id)]);
    }

    public function storeDepartment(Request $request)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'code'         => 'nullable|string|max:40',
            'description'  => 'nullable|string|max:500',
            'head_user_id' => 'nullable|integer',
            'is_active'    => 'nullable|boolean',
        ]);

        return response()->json([
            'message' => 'Department created.',
            'data'    => $this->catalog->createDepartment($request->user()->tenant_id, $data),
        ], 201);
    }

    public function updateDepartment(Request $request, int $department)
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|max:120',
            'code'         => 'nullable|string|max:40',
            'description'  => 'nullable|string|max:500',
            'head_user_id' => 'nullable|integer',
            'is_active'    => 'sometimes|boolean',
        ]);

        return response()->json([
            'message' => 'Department updated.',
            'data'    => $this->catalog->updateDepartment($this->findDepartment($request, $department), $data),
        ]);
    }

    public function destroyDepartment(Request $request, int $department)
    {
        $this->catalog->deleteDepartment($this->findDepartment($request, $department));

        return response()->json(['message' => 'Department deleted.']);
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function findDepartment(Request $request, int $id): Department
    {
        $row = Department::forTenant($request->user()->tenant_id)->find($id);
        abort_unless($row, 404, 'Department not found.');

        return $row;
    }
}
