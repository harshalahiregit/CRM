<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvViolationService;
use App\Support\Tpv\ViolationType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Vendor Violations & Strike escalation (Sangoe TPV §26). Tenant-scoped. */
class TpvViolationController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private TpvViolationService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'data' => $this->service->list($tenantId, $request->only(['status', 'vendor_id', 'type'])),
            'escalations' => $this->service->escalations($tenantId),
            'types' => ViolationType::TYPES,
            'severities' => TpvVendorViolation::SEVERITIES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'type' => ['required', Rule::in(ViolationType::TYPES)],
            'severity' => ['nullable', Rule::in(TpvVendorViolation::SEVERITIES)],
            'description' => 'nullable|string',
            'project_id' => 'nullable|integer',
            'occurred_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return response()->json(
            $this->service->record($data, $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function update(Request $request, TpvVendorViolation $violation)
    {
        $this->assertTenant($request, $violation);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(TpvVendorViolation::STATUSES)],
            'severity' => ['sometimes', Rule::in(TpvVendorViolation::SEVERITIES)],
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        return response()->json($this->service->update($violation, $data));
    }

    public function destroy(Request $request, TpvVendorViolation $violation)
    {
        $this->assertTenant($request, $violation);
        $this->service->delete($violation);

        return response()->json(['deleted' => true]);
    }

    public function escalation(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->service->escalationFor($request->user()->tenant_id, $vendor->id));
    }

    /** Apply the escalation action — admin only. */
    public function enforce(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin may suspend or blacklist a vendor.');

        $data = $request->validate([
            'action' => 'required|in:suspend,blacklist',
            'reason' => 'nullable|string',
        ]);

        return response()->json($this->service->enforce($vendor, $data['action'], $request->user(), $data['reason'] ?? null));
    }
}
