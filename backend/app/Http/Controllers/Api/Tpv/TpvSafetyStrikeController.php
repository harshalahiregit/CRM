<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tpv\IssueSafetyStrikeRequest;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Services\Tpv\SafetyStrikeService;
use Illuminate\Http\Request;

class TpvSafetyStrikeController extends Controller
{
    public function __construct(private SafetyStrikeService $strikeService)
    {
    }

    /** The whole tenant's strike ledger. */
    public function index(Request $request)
    {
        return response()->json(
            $this->strikeService->list(
                $request->user()->tenant_id,
                $request->only(['severity', 'active', 'worker_id', 'vendor_id'])
            )
        );
    }

    public function forWorker(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerTenant($request, $worker);

        return response()->json([
            'strikes'      => $this->strikeService->listForWorker($worker),
            'active_count' => $this->strikeService->activeCount($worker),
        ]);
    }

    /** Issue a strike. May auto-terminate the worker — the response says so. */
    public function store(IssueSafetyStrikeRequest $request, TpvWorker $worker)
    {
        $this->assertWorkerTenant($request, $worker);

        return response()->json($this->strikeService->issue($worker, $request->validated(), $request->user()), 201);
    }

    /** Void a strike on appeal — never auto-restores access. */
    public function void(Request $request, TpvSafetyStrike $strike)
    {
        $this->assertStrikeTenant($request, $strike);

        $data = $request->validate(['reason' => 'required|string|max:255']);

        return response()->json($this->strikeService->void($strike, $request->user(), $data['reason']));
    }

    public function stats(Request $request)
    {
        return response()->json($this->strikeService->stats($request->user()->tenant_id));
    }

    private function assertWorkerTenant(Request $request, TpvWorker $worker): void
    {
        abort_unless((int) $worker->tenant_id === (int) $request->user()->tenant_id, 404, 'Worker not found');
    }

    private function assertStrikeTenant(Request $request, TpvSafetyStrike $strike): void
    {
        abort_unless((int) $strike->tenant_id === (int) $request->user()->tenant_id, 404, 'Strike not found');
    }
}
