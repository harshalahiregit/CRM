<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\Clearance\ClearanceDepartmentService;
use Illuminate\Http\Request;

/**
 * Exit-clearance departments and their authorities, under HR Settings.
 *
 * Gated on hr_settings rather than on the HR-queue predicate, like the approval
 * workflows and the onboarding checklist: deciding who may clear a department
 * is a different authority from clearing one. Somebody who actions clearances
 * must not thereby be able to make themselves the authority for every
 * department.
 */
class ClearanceDepartmentController extends Controller
{
    public function __construct(private ClearanceDepartmentService $departments)
    {
    }

    public function index(Request $request)
    {
        $this->canConfigure($request);

        return response()->json(['data' => $this->departments->list($this->tenant($request))]);
    }

    public function store(Request $request)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'name'         => 'required|string|max:100',
            'is_mandatory' => 'nullable|boolean',
        ]);

        return response()->json(
            ['data' => $this->departments->create($this->tenant($request), $data, $request->user())],
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'is_mandatory' => 'sometimes|boolean',
        ]);

        return response()->json(
            ['data' => $this->departments->update($this->tenant($request), $id, $data, $request->user())]
        );
    }

    public function setStatus(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json([
            'data' => $this->departments->setActive(
                $this->tenant($request), $id, (bool) $data['is_active'], $request->user()
            ),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->canConfigure($request);

        $this->departments->delete($this->tenant($request), $id, $request->user());

        return response()->json(['message' => 'Clearance department removed']);
    }

    public function reorder(Request $request)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        return response()->json([
            'data' => $this->departments->reorder($this->tenant($request), $data['ids'], $request->user()),
        ]);
    }

    /** Replace this department's authorities — named users and staff roles. */
    public function authorities(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'user_ids'         => 'nullable|array',
            'user_ids.*'       => 'integer',
            'staff_role_ids'   => 'nullable|array',
            'staff_role_ids.*' => 'integer',
        ]);

        return response()->json([
            'data' => $this->departments->setAuthorities(
                $this->tenant($request), $id, $data, $request->user()
            ),
        ]);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /** The same check the other configuration surfaces make, read the same way. */
    private function canConfigure(Request $request): void
    {
        $user = $request->user();

        $allowed = $user->isAdmin()
            || app(\App\Services\Auth\StaffPermissionService::class)->can(
                $user,
                \App\Support\Hr\StaffPermission::VIEW_GLOBAL,
                'hr_settings',
            );

        abort_unless($allowed, 403, 'You are not authorised to configure exit clearance departments');
    }
}
