<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\Posh\PoshCommitteeService;
use Illuminate\Http\Request;

/**
 * POSH committee configuration, under HR Settings.
 *
 * Gated on hr_settings, the same check the approval workflows, the onboarding
 * checklist and the clearance departments make — read from the same place so
 * the configuration surfaces cannot drift apart.
 *
 * THIS IS CONFIGURATION AUTHORITY ONLY, and the distinction matters more here
 * than anywhere else in the product. StaffPermissionService has an
 * administrator bypass, which is right for editing a master and would be
 * completely wrong for reading a harassment complaint. When cases exist, their
 * access will go through a POSH resolver of its own that asks one question —
 * is this person a member of this case — with no bypass, no HR-queue path and
 * no data-scope path. Nothing in this controller is a step towards that, and
 * no endpoint here exposes any case data, because none exists yet.
 */
class PoshCommitteeController extends Controller
{
    public function __construct(private PoshCommitteeService $committees)
    {
    }

    public function index(Request $request)
    {
        $this->canConfigure($request);

        return response()->json(['data' => $this->committees->list($this->tenant($request))]);
    }

    public function store(Request $request)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'name'            => 'required|string|max:150',
            'quorum_mode'     => 'nullable|string|max:20',
            'quorum_required' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            ['data' => $this->committees->create($this->tenant($request), $data, $request->user())],
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'name'            => 'sometimes|string|max:150',
            'quorum_mode'     => 'sometimes|string|max:20',
            'quorum_required' => 'sometimes|nullable|integer|min:1',
        ]);

        return response()->json(
            ['data' => $this->committees->update($this->tenant($request), $id, $data, $request->user())]
        );
    }

    public function setStatus(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json([
            'data' => $this->committees->setActive(
                $this->tenant($request), $id, (bool) $data['is_active'], $request->user()
            ),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->canConfigure($request);

        $this->committees->delete($this->tenant($request), $id, $request->user());

        return response()->json(['message' => 'Committee removed']);
    }

    /* ── roles ────────────────────────────────────────────────────────── */

    public function storeRole(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'label'           => 'required|string|max:150',
            'key'             => 'nullable|string|max:60',
            'can_manage_case' => 'nullable|boolean',
            'is_active'       => 'nullable|boolean',
        ]);

        return response()->json(
            ['data' => $this->committees->addRole($this->tenant($request), $id, $data, $request->user())],
            201
        );
    }

    public function updateRole(Request $request, int $id, int $roleId)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'label'           => 'sometimes|string|max:150',
            'key'             => 'sometimes|string|max:60',
            'can_manage_case' => 'sometimes|boolean',
            'is_active'       => 'sometimes|boolean',
        ]);

        return response()->json([
            'data' => $this->committees->updateRole(
                $this->tenant($request), $id, $roleId, $data, $request->user()
            ),
        ]);
    }

    public function destroyRole(Request $request, int $id, int $roleId)
    {
        $this->canConfigure($request);

        return response()->json([
            'data' => $this->committees->deleteRole($this->tenant($request), $id, $roleId, $request->user()),
        ]);
    }

    /* ── members ──────────────────────────────────────────────────────── */

    public function setMembers(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'members'             => 'present|array',
            'members.*.user_id'   => 'required|integer',
            'members.*.role_id'   => 'required|integer',
            'members.*.is_active' => 'nullable|boolean',
        ]);

        return response()->json([
            'data' => $this->committees->setMembers(
                $this->tenant($request), $id, $data['members'], $request->user()
            ),
        ]);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function canConfigure(Request $request): void
    {
        $user = $request->user();

        $allowed = $user->isAdmin()
            || app(\App\Services\Auth\StaffPermissionService::class)->can(
                $user,
                \App\Support\Hr\StaffPermission::VIEW_GLOBAL,
                'hr_settings',
            );

        abort_unless($allowed, 403, 'You are not authorised to configure POSH committees');
    }
}
