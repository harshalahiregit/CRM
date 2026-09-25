<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\OnboardingChecklistService;
use Illuminate\Http\Request;

/**
 * The onboarding checklist master, under HR Settings.
 *
 * Gated on hr_settings rather than on the HR-queue predicate, for the same
 * reason the approval workflows are: configuring what everybody must do is a
 * different authority from doing it. Somebody who runs onboardings should not
 * thereby be able to remove the steps they are measured against.
 */
class OnboardingChecklistController extends Controller
{
    public function __construct(private OnboardingChecklistService $checklist)
    {
    }

    public function index(Request $request)
    {
        $this->canConfigure($request);

        return response()->json(['data' => $this->checklist->list($this->tenant($request))]);
    }

    public function store(Request $request)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'title'        => 'required|string|max:200',
            'category'     => 'nullable|string|max:50',
            'owner_role'   => 'nullable|string|max:30',
            'is_mandatory' => 'nullable|boolean',
        ]);

        return response()->json(
            ['data' => $this->checklist->create($this->tenant($request), $data, $request->user())],
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'title'        => 'sometimes|string|max:200',
            'category'     => 'sometimes|string|max:50',
            'owner_role'   => 'sometimes|string|max:30',
            'is_mandatory' => 'sometimes|boolean',
            'is_active'    => 'sometimes|boolean',
        ]);

        return response()->json(
            ['data' => $this->checklist->update($this->tenant($request), $id, $data, $request->user())]
        );
    }

    public function setStatus(Request $request, int $id)
    {
        $this->canConfigure($request);

        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json([
            'data' => $this->checklist->setActive(
                $this->tenant($request), $id, (bool) $data['is_active'], $request->user()
            ),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->canConfigure($request);

        $this->checklist->delete($this->tenant($request), $id, $request->user());

        return response()->json(['message' => 'Checklist task removed']);
    }

    public function reorder(Request $request)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        return response()->json([
            'data' => $this->checklist->reorder($this->tenant($request), $data['ids'], $request->user()),
        ]);
    }

    /** Turn the implicit defaults into editable rows. */
    public function adoptDefaults(Request $request)
    {
        $this->canConfigure($request);

        return response()->json([
            'data' => $this->checklist->adoptDefaults($this->tenant($request), $request->user()),
        ]);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /**
     * Settings authority, deliberately not the onboarding authority.
     *
     * The same check ApprovalWorkflowController makes, read the same way, so
     * the two configuration surfaces cannot drift apart.
     */
    private function canConfigure(Request $request): void
    {
        $user = $request->user();

        $allowed = $user->isAdmin()
            || app(\App\Services\Auth\StaffPermissionService::class)->can(
                $user,
                \App\Support\Hr\StaffPermission::VIEW_GLOBAL,
                'hr_settings',
            );

        abort_unless($allowed, 403, 'You are not authorised to configure the onboarding checklist');
    }
}
