<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessRole;
use App\Models\Access\Department;
use App\Services\Access\AccessCatalogService;
use Illuminate\Http\Request;

/**
 * Roles and departments, maintained from Settings.
 *
 * Admin-only, and tenant-scoped on every read and write — a role or department
 * is looked up THROUGH the tenant, so an id from another workspace resolves to
 * nothing rather than to someone else's record.
 */
class AccessCatalogController extends Controller
{
    public function __construct(private AccessCatalogService $catalog) {}

    /* ── Roles ──────────────────────────────────────────────────────────── */

    public function roles(Request $request)
    {
        return response()->json([
            'data' => $this->catalog->roles($request->user()->tenant_id),
            // Shown read-only beside the editable list: each account type is a
            // separate portal with its own login, so the UI can explain why
            // these are not editable rather than leaving a confusing gap.
            'account_types' => AccessCatalogService::ACCOUNT_TYPES,
        ]);
    }

    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            // Optional: derived from the name when omitted. This is what a route
            // guard spells, so it is fixed at creation and never editable after.
            'slug'        => 'nullable|string|max:80|regex:/^[a-zA-Z0-9_\- ]+$/',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'nullable|boolean',
        ]);

        return response()->json([
            'message' => 'Role created.',
            'data'    => $this->catalog->createRole($request->user()->tenant_id, $data),
        ], 201);
    }

    public function updateRole(Request $request, int $role)
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'sometimes|boolean',
        ]);

        return response()->json([
            'message' => 'Role updated.',
            'data'    => $this->catalog->updateRole($this->findRole($request, $role), $data),
        ]);
    }

    public function destroyRole(Request $request, int $role)
    {
        $this->catalog->deleteRole($this->findRole($request, $role));

        return response()->json(['message' => 'Role deleted.']);
    }

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

    private function findRole(Request $request, int $id): AccessRole
    {
        $row = AccessRole::forTenant($request->user()->tenant_id)->find($id);
        abort_unless($row, 404, 'Role not found.');

        return $row;
    }

    private function findDepartment(Request $request, int $id): Department
    {
        $row = Department::forTenant($request->user()->tenant_id)->find($id);
        abort_unless($row, 404, 'Department not found.');

        return $row;
    }
}
