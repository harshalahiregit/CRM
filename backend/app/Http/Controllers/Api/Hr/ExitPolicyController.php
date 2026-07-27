<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ExitPolicyService;
use Illuminate\Http\Request;

/**
 * Exit Management → Exit Policies (Phase 1). Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class ExitPolicyController extends Controller
{
    public function __construct(private ExitPolicyService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['status', 'search'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json($this->service->create($this->validated($request), $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user()));
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json($this->service->setStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'                    => "$req|string|max:120",
            'grade_id'                => 'nullable|integer',
            'designation_id'          => 'nullable|integer',
            'department_id'           => 'nullable|integer',
            'default_exit_type_id'    => 'nullable|integer',
            'notice_days'             => 'nullable|integer|min:0|max:365',
            'buyout_allowed'          => 'boolean',
            'recovery_allowed'        => 'boolean',
            'leave_encashment'        => 'boolean',
            'gratuity_applicable'     => 'boolean',
            'clearance_required'      => 'boolean',
            'exit_interview_required' => 'boolean',
            'description'             => 'nullable|string',
            'is_active'               => 'boolean',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage exit settings');
    }
}
