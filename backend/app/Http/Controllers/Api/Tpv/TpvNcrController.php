<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvNcr;
use App\Services\Tpv\TpvNcrService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Non-Conformance Reports (Sangoe TPV §24). Tenant-scoped. */
class TpvNcrController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private TpvNcrService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status', 'severity', 'vendor_id'])),
            'severities' => TpvNcr::SEVERITIES,
            'statuses' => TpvNcr::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateNcr($request);

        return response()->json(
            $this->service->create($data, $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function update(Request $request, TpvNcr $ncr)
    {
        $this->assertTenant($request, $ncr);

        return response()->json($this->service->update($ncr, $this->validateNcr($request, true)));
    }

    public function transition(Request $request, TpvNcr $ncr)
    {
        $this->assertTenant($request, $ncr);
        $data = $request->validate([
            'status' => ['required', Rule::in(TpvNcr::STATUSES)],
            'remarks' => 'nullable|string',
        ]);

        return response()->json($this->service->transition($ncr, $data['status'], $request->user(), $data['remarks'] ?? null));
    }

    public function destroy(Request $request, TpvNcr $ncr)
    {
        $this->assertTenant($request, $ncr);
        $this->service->delete($ncr);

        return response()->json(['deleted' => true]);
    }

    private function validateNcr(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title' => "$req|string|max:200",
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'project_id' => 'nullable|integer',
            'requirement' => 'nullable|string',
            'finding' => 'nullable|string',
            'severity' => ['nullable', Rule::in(TpvNcr::SEVERITIES)],
            'status' => ['nullable', Rule::in(TpvNcr::STATUSES)],
            'responsible_by' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
            'response' => 'nullable|string',
            'corrective_action' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
    }
}
