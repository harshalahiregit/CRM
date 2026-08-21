<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseNcr;
use App\Services\Purchase\PurchaseNcrService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Non-Conformance Reports — mirror of the TPV register (parity). Tenant-scoped. */
class PurchaseNcrController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseNcrService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status', 'severity', 'vendor_id'])),
            'severities' => PurchaseNcr::SEVERITIES,
            'statuses' => PurchaseNcr::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        return response()->json(
            $this->service->create($this->validateNcr($request), $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function update(Request $request, PurchaseNcr $ncr)
    {
        $this->assertTenant($request, $ncr);

        return response()->json($this->service->update($ncr, $this->validateNcr($request, true)));
    }

    public function transition(Request $request, PurchaseNcr $ncr)
    {
        $this->assertTenant($request, $ncr);
        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseNcr::STATUSES)],
            'remarks' => 'nullable|string',
        ]);

        return response()->json($this->service->transition($ncr, $data['status'], $request->user(), $data['remarks'] ?? null));
    }

    public function destroy(Request $request, PurchaseNcr $ncr)
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
            'purchase_vendor_id' => 'nullable|integer|exists:purchase_vendors,id',
            'requirement' => 'nullable|string',
            'finding' => 'nullable|string',
            'severity' => ['nullable', Rule::in(PurchaseNcr::SEVERITIES)],
            'status' => ['nullable', Rule::in(PurchaseNcr::STATUSES)],
            'responsible_by' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
            'response' => 'nullable|string',
            'corrective_action' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
    }
}
