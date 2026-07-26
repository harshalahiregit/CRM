<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\LeavePolicyService;
use Illuminate\Http\Request;

/**
 * Leave → Leave Policies (Phase 1). Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class LeavePolicyController extends Controller
{
    public function __construct(private LeavePolicyService $service)
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
            'name'                     => "$req|string|max:120",
            'applies_to'               => 'nullable|string|max:40',
            'grade_id'                 => 'nullable|integer',
            'designation_id'           => 'nullable|integer',
            'probation_allowed'        => 'boolean',
            'notice_period_allowed'    => 'boolean',
            'weekends_count'           => 'boolean',
            'holidays_count'           => 'boolean',
            'half_day_allowed'         => 'boolean',
            'negative_balance_allowed' => 'boolean',
            'description'              => 'nullable|string',
            'is_active'               => 'boolean',
            'leave_types'                       => 'nullable|array',
            'leave_types.*.leave_type_id'       => 'required|integer',
            'leave_types.*.yearly_allocation'   => 'nullable|numeric|min:0',
            'leave_types.*.carry_forward_limit' => 'nullable|numeric|min:0',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage leave settings');
    }
}
