<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseOffboarding;
use App\Services\Purchase\PurchaseOffboardingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Offboarding / Closure — mirror of the TPV engine (parity). Tenant-scoped. */
class PurchaseOffboardingController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseOffboardingService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status'])),
            'final_statuses' => PurchaseOffboarding::FINAL_STATUSES,
            'checklist_template' => PurchaseOffboarding::defaultChecklist(),
        ]);
    }

    public function show(Request $request, PurchaseOffboarding $offboarding)
    {
        $this->assertTenant($request, $offboarding);

        return response()->json($this->service->detail($offboarding));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_vendor_id' => 'required|integer|exists:purchase_vendors,id',
            'reason' => 'nullable|string',
        ]);

        return response()->json($this->service->initiate($data, $request->user()->tenant_id, $request->user()->id), 201);
    }

    public function updateChecklist(Request $request, PurchaseOffboarding $offboarding)
    {
        $this->assertTenant($request, $offboarding);
        $data = $request->validate([
            'checklist' => 'required|array',
            'checklist.*.key' => 'required|string',
            'checklist.*.label' => 'required|string',
            'checklist.*.done' => 'required|boolean',
            'checklist.*.notes' => 'nullable|string',
        ]);

        return response()->json($this->service->updateChecklist($offboarding, $data['checklist']));
    }

    public function complete(Request $request, PurchaseOffboarding $offboarding)
    {
        $this->assertTenant($request, $offboarding);
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin may complete an offboarding.');

        $data = $request->validate([
            'final_status' => ['required', Rule::in(PurchaseOffboarding::FINAL_STATUSES)],
            'reason' => 'nullable|string',
            'lessons_learned' => 'nullable|string',
        ]);

        return response()->json($this->service->complete($offboarding, $data, $request->user()));
    }

    public function destroy(Request $request, PurchaseOffboarding $offboarding)
    {
        $this->assertTenant($request, $offboarding);
        $this->service->delete($offboarding);

        return response()->json(['deleted' => true]);
    }
}
