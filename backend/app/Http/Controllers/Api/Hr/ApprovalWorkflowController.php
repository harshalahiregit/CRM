<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\Approval\WorkflowConfigService;
use Illuminate\Http\Request;

/**
 * Approval workflow configuration — HR Settings.
 *
 * Thin: assert permission, validate, delegate. Configuring who approves leave
 * is settings administration, not an HR queue action, so it is gated on
 * hr_settings rather than on the HR-queue predicate an approver holds. An
 * approver must not be able to edit the ladder they sit on.
 */
class ApprovalWorkflowController extends Controller
{
    public function __construct(private WorkflowConfigService $service)
    {
    }

    public function index(Request $request)
    {
        $this->canConfigure($request);

        return response()->json(['data' => $this->service->overview($this->tenant($request))]);
    }

    public function show(Request $request, string $process)
    {
        $this->canConfigure($request);

        return response()->json($this->service->show($this->tenant($request), $process));
    }

    public function save(Request $request, string $process)
    {
        $this->canConfigure($request);

        $data = $request->validate([
            'name'                    => 'nullable|string|max:150',
            'is_active'               => 'nullable|boolean',
            'steps'                   => 'present|array|max:10',
            'steps.*.name'            => 'nullable|string|max:150',
            'steps.*.approver_type'   => 'required|string|max:40',
            'steps.*.approver_ref'    => 'nullable|integer',
            'steps.*.levels_up'       => 'nullable|integer|min:1|max:10',
            'steps.*.conditions'      => 'nullable|array',
            'steps.*.is_active'       => 'nullable|boolean',
        ]);

        return response()->json($this->service->save($this->tenant($request), $process, $data, $request->user()));
    }

    public function setStatus(Request $request, string $process)
    {
        $this->canConfigure($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json(
            $this->service->setStatus($this->tenant($request), $process, (bool) $data['is_active'], $request->user())
        );
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /**
     * Settings authority, deliberately not the approval authority.
     *
     * Admins keep access because they already administer every other HR master.
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

        abort_unless($allowed, 403, 'You are not authorised to configure approval workflows');
    }
}
