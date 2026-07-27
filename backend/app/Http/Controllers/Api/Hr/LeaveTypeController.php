<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrLeaveType;
use App\Services\Hr\LeaveTypeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave → Leave Types master (Phase 1). Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class LeaveTypeController extends Controller
{
    public function __construct(private LeaveTypeService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['category', 'status', 'search'])));
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
            'name'                => "$req|string|max:120",
            'code'                => "$req|string|max:40",
            'category'            => [$partial ? 'sometimes' : 'required', Rule::in(HrLeaveType::CATEGORIES)],
            'paid'                => 'boolean',
            'yearly_limit'        => 'nullable|numeric|min:0',
            'carry_forward'       => 'boolean',
            'max_carry_forward'   => 'nullable|numeric|min:0',
            'requires_attachment' => 'boolean',
            'requires_approval'   => 'boolean',
            'color'               => 'nullable|string|max:20',
            'description'         => 'nullable|string',
            'is_active'           => 'boolean',
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
