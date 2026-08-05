<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrSalaryComponent;
use App\Services\Hr\VariableEarningService;
use Illuminate\Http\Request;

/**
 * #31 — commissions and incentives.
 *
 * Everything here is money, so every route is HR-gated. Raising and approving are
 * deliberately the same permission as the rest of payroll rather than a new one:
 * inventing a permission nobody has been granted would leave the screen unusable.
 */
class VariableEarningController extends Controller
{
    public function __construct(private VariableEarningService $service)
    {
    }

    public function index(Request $request)
    {
        $this->can($request);

        return response()->json(['data' => $this->service->list(
            $this->tenant($request),
            $request->only(['employee_id', 'period', 'status', 'component_id'])
        )]);
    }

    /** Earning components a commission may be paid against. */
    public function components(Request $request)
    {
        $this->can($request);

        return response()->json(['data' => HrSalaryComponent::where('tenant_id', $this->tenant($request))
            ->where('type', 'Earning')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'taxable', 'pf_applicable', 'esic_applicable'])]);
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json(
            $this->service->save($request->all(), $this->tenant($request), $request->user()), 201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json(
            $this->service->save($request->all() + ['id' => $id], $this->tenant($request), $request->user())
        );
    }

    public function approve(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->approve($id, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return response()->json($this->service->reject($id, $this->tenant($request), $data['remarks'], $request->user()));
    }

    public function destroy(Request $request, int $id)
    {
        $this->can($request);
        $this->service->destroy($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Variable earning deleted']);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage variable earnings');
    }
}
