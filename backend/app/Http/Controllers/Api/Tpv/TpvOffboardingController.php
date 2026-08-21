<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvOffboarding;
use App\Services\Tpv\TpvOffboardingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Offboarding / Closure (Sangoe TPV §29). Tenant-scoped. */
class TpvOffboardingController extends Controller
{
    public function __construct(private TpvOffboardingService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status'])),
            'final_statuses' => TpvOffboarding::FINAL_STATUSES,
        ]);
    }

    public function show(Request $request, TpvOffboarding $offboarding)
    {
        $this->assertTenant($request, $offboarding);

        return response()->json($this->service->detail($offboarding));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'reason' => 'nullable|string',
        ]);

        return response()->json($this->service->initiate($data, $request->user()->tenant_id, $request->user()->id), 201);
    }

    public function updateChecklist(Request $request, TpvOffboarding $offboarding)
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

    public function complete(Request $request, TpvOffboarding $offboarding)
    {
        $this->assertTenant($request, $offboarding);
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin may complete an offboarding.');

        $data = $request->validate([
            'final_status' => ['required', Rule::in(TpvOffboarding::FINAL_STATUSES)],
            'reason' => 'nullable|string',
            'lessons_learned' => 'nullable|string',
        ]);

        return response()->json($this->service->complete($offboarding, $data, $request->user()));
    }

    public function destroy(Request $request, TpvOffboarding $offboarding)
    {
        $this->assertTenant($request, $offboarding);
        $this->service->delete($offboarding);

        return response()->json(['deleted' => true]);
    }
}
