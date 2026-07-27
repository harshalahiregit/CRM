<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\OrganizationService;
use Illuminate\Http\Request;

/**
 * Organization Setup — Department / Designation / Grade / Role masters, plus the
 * overview counters, dropdown options and reporting hierarchy. Thin: validate,
 * delegate to OrganizationService, return JSON. Every mutation is guarded by the
 * same HR-management permission used across the module.
 */
class OrganizationController extends Controller
{
    public function __construct(private OrganizationService $org)
    {
    }

    /* ── Read ─────────────────────────────────────────────────────────── */

    public function overview(Request $request)
    {
        return response()->json($this->org->overview($this->tenant($request)));
    }

    public function options(Request $request)
    {
        return response()->json($this->org->options($this->tenant($request)));
    }

    /**
     * ONE shared master-data feed for every Recruitment dropdown — departments,
     * designations, grades, roles, shifts, business_units, employee_levels, managers,
     * locations. Read-only (tenant-scoped); reuses the Organization Setup masters.
     */
    public function masterData(Request $request)
    {
        return response()->json($this->org->masterData($this->tenant($request)));
    }

    public function hierarchy(Request $request)
    {
        return response()->json($this->org->hierarchy($this->tenant($request)));
    }

    /* ── Departments ──────────────────────────────────────────────────── */

    public function departments(Request $request)
    {
        return response()->json($this->org->departments($this->tenant($request)));
    }

    public function storeDepartment(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'             => 'required|string|max:120',
            'code'             => 'nullable|string|max:40',
            'head_employee_id' => 'nullable|integer',
            'description'      => 'nullable|string',
            'is_active'        => 'boolean',
        ]);

        return response()->json($this->org->createDepartment($data, $this->tenant($request), $request->user()), 201);
    }

    public function updateDepartment(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'             => 'sometimes|required|string|max:120',
            'code'             => 'nullable|string|max:40',
            'head_employee_id' => 'nullable|integer',
            'description'      => 'nullable|string',
            'is_active'        => 'boolean',
        ]);

        return response()->json($this->org->updateDepartment($id, $data, $this->tenant($request), $request->user()));
    }

    public function destroyDepartment(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->org->deleteDepartment($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Designations ─────────────────────────────────────────────────── */

    public function designations(Request $request)
    {
        return response()->json($this->org->designations($this->tenant($request)));
    }

    public function storeDesignation(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'code'        => 'nullable|string|max:40',
            'grade_id'    => 'nullable|integer',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        return response()->json($this->org->createDesignation($data, $this->tenant($request), $request->user()), 201);
    }

    public function updateDesignation(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:120',
            'code'        => 'nullable|string|max:40',
            'grade_id'    => 'nullable|integer',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        return response()->json($this->org->updateDesignation($id, $data, $this->tenant($request), $request->user()));
    }

    public function destroyDesignation(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->org->deleteDesignation($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Grades ───────────────────────────────────────────────────────── */

    public function grades(Request $request)
    {
        return response()->json($this->org->grades($this->tenant($request)));
    }

    public function storeGrade(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'code'        => 'nullable|string|max:40',
            'level'       => 'nullable|integer|min:1|max:100',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        return response()->json($this->org->createGrade($data, $this->tenant($request), $request->user()), 201);
    }

    public function updateGrade(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:120',
            'code'        => 'nullable|string|max:40',
            'level'       => 'nullable|integer|min:1|max:100',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        return response()->json($this->org->updateGrade($id, $data, $this->tenant($request), $request->user()));
    }

    public function destroyGrade(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->org->deleteGrade($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Roles ────────────────────────────────────────────────────────── */

    public function roles(Request $request)
    {
        return response()->json($this->org->roles($this->tenant($request)));
    }

    public function storeRole(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'code'        => 'nullable|string|max:40',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        return response()->json($this->org->createRole($data, $this->tenant($request), $request->user()), 201);
    }

    public function updateRole(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:120',
            'code'        => 'nullable|string|max:40',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        return response()->json($this->org->updateRole($id, $data, $this->tenant($request), $request->user()));
    }

    public function destroyRole(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->org->deleteRole($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage organization setup');
    }
}
