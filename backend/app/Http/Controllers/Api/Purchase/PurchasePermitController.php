<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseWorkPermit;
use App\Services\Purchase\PurchasePermitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase Permit To Work — mirror of TPV's PermitController.
 *
 * The decisions (approve / reject / activate / close) are admin-gated on the
 * route, not here: a permit is the document that says dangerous work may
 * proceed, and staff raising one must not also be the ones clearing it.
 */
class PurchasePermitController extends Controller
{
    public function __construct(private PurchasePermitService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list(
                (int) $request->user()->tenant_id,
                $request->only(['status', 'type', 'vendor_id'])
            ),
        ]);
    }

    public function stats(Request $request)
    {
        return response()->json($this->service->stats((int) $request->user()->tenant_id));
    }

    public function show(Request $request, PurchaseWorkPermit $permit)
    {
        $this->assertTenant($request, $permit);

        return response()->json($permit->load(['jsaSteps', 'vendor:id,company_name,purchase_vendor_code']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // Required, not optional: purchase_work_permits.purchase_vendor_id is
            // NOT NULL, and a permit to work with no one to hold it is not a
            // permit. (TPV allows an unattached permit; Purchase's schema does not.)
            'purchase_vendor_id' => 'required|integer',
            'type'               => ['required', Rule::in(PurchaseWorkPermit::TYPES)],
            'title'              => 'required|string|max:190',
            'location'           => 'nullable|string|max:190',
            'description'        => 'nullable|string|max:5000',
            'hazards'            => 'nullable|string|max:5000',
            'precautions'        => 'nullable|string|max:5000',
            'valid_from'         => 'nullable|date',
            'valid_to'           => 'nullable|date|after_or_equal:valid_from',
        ]);

        return response()->json(
            $this->service->create((int) $request->user()->tenant_id, $data, $request->user()),
            201
        );
    }

    /** Add one JSA row. Step numbers are assigned on append, never supplied. */
    public function addJsaStep(Request $request, PurchaseWorkPermit $permit)
    {
        $this->assertTenant($request, $permit);

        $data = $request->validate([
            'activity'      => 'required|string|max:500',
            'hazard'        => 'nullable|string|max:500',
            'control'       => 'nullable|string|max:500',
            'residual_risk' => 'nullable|string|max:30',
        ]);

        return response()->json($this->service->addJsaStep($permit, $data), 201);
    }

    public function approve(Request $request, PurchaseWorkPermit $permit)
    {
        $this->assertTenant($request, $permit);
        $data = $request->validate(['remarks' => 'nullable|string|max:1000']);

        return response()->json($this->service->approve($permit, $request->user(), $data['remarks'] ?? null));
    }

    /** Rejection remarks are REQUIRED — a refusal nobody can answer is not one. */
    public function reject(Request $request, PurchaseWorkPermit $permit)
    {
        $this->assertTenant($request, $permit);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return response()->json($this->service->reject($permit, $request->user(), $data['remarks']));
    }

    public function activate(Request $request, PurchaseWorkPermit $permit)
    {
        $this->assertTenant($request, $permit);

        return response()->json($this->service->activate($permit, $request->user()));
    }

    public function close(Request $request, PurchaseWorkPermit $permit)
    {
        $this->assertTenant($request, $permit);

        return response()->json($this->service->close($permit, $request->user()));
    }

    private function assertTenant(Request $request, PurchaseWorkPermit $permit): void
    {
        abort_unless(
            (int) $permit->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Permit not found'
        );
    }
}
