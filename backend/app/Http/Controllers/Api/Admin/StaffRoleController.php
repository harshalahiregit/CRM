<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffRole;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roles, the old CRM's way: records rather than a map in a JavaScript file.
 *
 * The list seeds itself on first read, so a workspace never has to be migrated
 * into having roles and a tenant created next month gets them the same way.
 */
class StaffRoleController extends Controller
{
    public function __construct(private StaffRoleService $roles)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'roles' => $this->roles->forTenant((int) $request->user()->tenant_id),
                // The vocabulary, so the permission grid is built from what the
                // server actually enforces rather than a list copied into the client.
                'modules'      => StaffPermission::MODULES,
                'capabilities' => StaffPermission::CAPABILITIES,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:80',
            'description'   => 'nullable|string|max:255',
            'permissions'   => 'nullable|array',
        ]);

        $role = $this->roles->create((int) $request->user()->tenant_id, $data);

        return response()->json(['status' => 'success', 'message' => 'Role created.', 'data' => $role], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:80',
            'description' => 'nullable|string|max:255',
            'permissions' => 'sometimes|array',
        ]);

        $role = $this->roles->update($this->find($request, $id), $data);

        return response()->json([
            'status'  => 'success',
            // Worth saying plainly: unlike the old copy-on-create templates, this
            // reaches everybody who holds the role, immediately.
            'message' => 'Role updated. Everyone on this role now has these permissions.',
            'data'    => $role,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->roles->delete($this->find($request, $id));

        return response()->json(['status' => 'success', 'message' => 'Role deleted.']);
    }

    private function find(Request $request, int $id): StaffRole
    {
        return StaffRole::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
}
