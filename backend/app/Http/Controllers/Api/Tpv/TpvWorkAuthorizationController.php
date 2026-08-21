<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvWorker;
use App\Services\Tpv\TpvWorkAuthorizationService;
use Illuminate\Http\Request;

/** Unified Work Authorization (Sangoe TPV §19). Read-only aggregator. */
class TpvWorkAuthorizationController extends Controller
{
    public function __construct(private TpvWorkAuthorizationService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->roster($request->user()->tenant_id, $request->only(['vendor_id'])),
        ]);
    }

    public function worker(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $activity = null;
        if ($request->filled('activity_id')) {
            $activity = TpvActivity::where('tenant_id', $request->user()->tenant_id)
                ->find($request->query('activity_id'));
        }

        return response()->json($this->service->authorize($worker, $activity));
    }
}
