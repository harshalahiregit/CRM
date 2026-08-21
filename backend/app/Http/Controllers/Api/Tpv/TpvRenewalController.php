<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvRenewal;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvRenewalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Renewal & Extension (Sangoe TPV §28). Tenant-scoped. */
class TpvRenewalController extends Controller
{
    public function __construct(private TpvRenewalService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status', 'vendor_id'])),
            'decisions' => TpvRenewal::DECISIONS,
        ]);
    }

    /** Preview the assessment for a vendor before initiating a renewal. */
    public function assess(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->service->assess($vendor));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'contract_id' => 'nullable|integer|exists:tpv_contracts,id',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return response()->json($this->service->initiate($data, $request->user()->tenant_id, $request->user()->id), 201);
    }

    public function reassess(Request $request, TpvRenewal $renewal)
    {
        $this->assertTenant($request, $renewal);

        return response()->json($this->service->reassess($renewal));
    }

    public function decide(Request $request, TpvRenewal $renewal)
    {
        $this->assertTenant($request, $renewal);
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin may decide a renewal.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(TpvRenewal::DECISIONS)],
            'conditions' => 'nullable|string',
            'new_end_date' => 'nullable|date',
        ]);

        return response()->json($this->service->decide($renewal, $data, $request->user()));
    }

    public function destroy(Request $request, TpvRenewal $renewal)
    {
        $this->assertTenant($request, $renewal);
        $this->service->delete($renewal);

        return response()->json(['deleted' => true]);
    }
}
