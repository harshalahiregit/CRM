<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrSalaryComponent;
use App\Services\Hr\SalaryComponentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payroll → Salary Components master. Thin: validate, delegate to the service,
 * return JSON. Reads are open to any HR user; writes require HR-queue management
 * (same permission gate used across the module). No hard delete — status toggle only.
 */
class SalaryComponentController extends Controller
{
    public function __construct(private SalaryComponentService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data'  => $this->service->list($this->tenant($request), $request->only(['type', 'status', 'search'])),
            'stats' => $this->service->stats($this->tenant($request)),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->create($this->validated($request), $this->tenant($request), $request->user()),
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user())
        );
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json(
            $this->service->setStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user())
        );
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'             => "$req|string|max:120",
            'code'             => "$req|string|max:40",
            'type'             => [$partial ? 'sometimes' : 'required', Rule::in(HrSalaryComponent::TYPES)],
            'calculation_type' => [$partial ? 'sometimes' : 'required', Rule::in(HrSalaryComponent::CALC_TYPES)],
            'amount_value'     => 'nullable|numeric|min:0',
            'percentage_value' => 'nullable|numeric|min:0|max:100',
            'based_on'         => 'nullable|string|max:120',
            'description'      => 'nullable|string',
            'is_active'        => 'boolean',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage payroll settings');
    }
}
