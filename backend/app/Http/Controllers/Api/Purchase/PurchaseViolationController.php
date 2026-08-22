<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorViolation;
use App\Services\Purchase\PurchaseViolationService;
use App\Support\Purchase\PurchaseViolationType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Vendor Violations & Strike escalation — mirror of the TPV engine (parity). Tenant-scoped. */
class PurchaseViolationController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseViolationService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'data' => $this->service->list($tenantId, $request->only(['status', 'vendor_id', 'type'])),
            'escalations' => $this->service->escalations($tenantId),
            'types' => PurchaseViolationType::TYPES,
            'severities' => PurchaseVendorViolation::SEVERITIES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_vendor_id' => 'required|integer|exists:purchase_vendors,id',
            'type' => ['required', Rule::in(PurchaseViolationType::TYPES)],
            'severity' => ['nullable', Rule::in(PurchaseVendorViolation::SEVERITIES)],
            'description' => 'nullable|string',
            'occurred_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return response()->json(
            $this->service->record($data, $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function update(Request $request, PurchaseVendorViolation $violation)
    {
        $this->assertTenant($request, $violation);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(PurchaseVendorViolation::STATUSES)],
            'severity' => ['sometimes', Rule::in(PurchaseVendorViolation::SEVERITIES)],
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        return response()->json($this->service->update($violation, $data));
    }

    public function destroy(Request $request, PurchaseVendorViolation $violation)
    {
        $this->assertTenant($request, $violation);
        $this->service->delete($violation);

        return response()->json(['deleted' => true]);
    }

    public function escalation(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->service->escalationFor($request->user()->tenant_id, $purchaseVendor->id));
    }

    /** Apply the escalation action — admin only. */
    public function enforce(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin may suspend or blacklist a vendor.');

        $data = $request->validate([
            'action' => 'required|in:suspend,blacklist',
            'reason' => 'nullable|string',
        ]);

        return response()->json($this->service->enforce($purchaseVendor, $data['action'], $request->user(), $data['reason'] ?? null));
    }
}
