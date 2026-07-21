<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvWorker;
use App\Services\Tpv\GateScanService;
use Illuminate\Http\Request;

/**
 * The authed side of the gate: attendance roster, per-worker history and the
 * gate log. Read-only — scanning itself happens on the public controller.
 */
class TpvGateLogController extends Controller
{
    public function __construct(private GateScanService $gateService)
    {
    }

    /** Who was on site on a given date (defaults to today). */
    public function roster(Request $request)
    {
        $data = $request->validate(['date' => 'nullable|date']);

        return response()->json($this->gateService->roster($request->user()->tenant_id, $data['date'] ?? null));
    }

    public function workerAttendance(Request $request, TpvWorker $worker)
    {
        abort_unless((int) $worker->tenant_id === (int) $request->user()->tenant_id, 404, 'Worker not found');

        $data = $request->validate(['days' => 'nullable|integer|min:1|max:365']);

        return response()->json($this->gateService->attendanceFor($worker, (int) ($data['days'] ?? 30)));
    }

    /** Every badge presented at the gate — admitted or refused. */
    public function log(Request $request)
    {
        return response()->json(
            $this->gateService->gateLog($request->user()->tenant_id, $request->only(['decision', 'worker_id', 'date']))
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->gateService->gateStats($request->user()->tenant_id));
    }
}
