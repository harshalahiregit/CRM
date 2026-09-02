<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseGateEvent;
use App\Models\Purchase\PurchaseWorker;
use App\Services\Purchase\PurchaseGateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Purchase site gate — mirror of TPV's gate controllers on Purchase tables.
 *
 * Purchase could compute whether a worker may enter but recorded nothing when
 * the question was asked, so it had no gate log and no attendance. These are the
 * reads and the one write that make both possible.
 *
 * Tenant-scoped throughout; a worker from another tenant 404s rather than 403s,
 * the same existence-hiding the rest of the Purchase admin API uses.
 */
class PurchaseGateController extends Controller
{
    public function __construct(private PurchaseGateService $gate)
    {
    }

    /** Record a badge scan and return the gate's verdict. */
    public function scan(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate([
            'action'     => ['nullable', Rule::in(['in', 'out'])],
            'gate'       => 'nullable|string|max:80',
            'scanned_at' => 'nullable|date',
        ]);

        return response()->json($this->gate->scan($worker, $data, $request), 201);
    }

    /** The gate log — every crossing, newest first. */
    public function log(Request $request)
    {
        return response()->json([
            'data' => $this->gate->log(
                (int) $request->user()->tenant_id,
                $request->only(['vendor_id', 'worker_id', 'decision', 'from', 'to', 'limit'])
            ),
        ]);
    }

    /** Counters for one day, plus who is still inside. */
    public function stats(Request $request)
    {
        return response()->json(
            $this->gate->stats((int) $request->user()->tenant_id, $request->query('date'))
        );
    }

    /** The live on-site roster. */
    public function onSite(Request $request)
    {
        return response()->json([
            'data' => $this->gate->onSite((int) $request->user()->tenant_id, $request->query('date')),
        ]);
    }

    /** One worker's attendance, grouped into days with hours on site. */
    public function workerAttendance(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json(
            $this->gate->workerAttendance($worker, $request->only(['from', 'to']))
        );
    }

    /* ── Non-person crossings (TPV §20) ──────────────────────────────────── */

    public function events(Request $request)
    {
        return response()->json([
            'data' => $this->gate->events(
                (int) $request->user()->tenant_id,
                $request->only(['event_kind', 'direction', 'vendor_id', 'from', 'to', 'limit'])
            ),
        ]);
    }

    public function storeEvent(Request $request)
    {
        $data = $request->validate([
            'event_kind'         => ['required', Rule::in(PurchaseGateEvent::KINDS)],
            'direction'          => ['required', Rule::in(PurchaseGateEvent::DIRECTIONS)],
            'purchase_vendor_id' => 'nullable|integer',
            'label'              => 'nullable|string|max:190',
            'reference'          => 'nullable|string|max:120',
            'quantity'           => 'nullable|numeric',
            'unit'               => 'nullable|string|max:30',
            'project'            => 'nullable|string|max:150',
            'location'           => 'nullable|string|max:150',
            'gate'               => 'nullable|string|max:80',
            'occurred_at'        => 'nullable|date',
            'details'            => 'nullable|string|max:5000',
        ]);

        return response()->json(
            $this->gate->recordEvent((int) $request->user()->tenant_id, $data, $request->user()),
            201
        );
    }

    private function assertTenant(Request $request, PurchaseWorker $worker): void
    {
        abort_unless(
            (int) $worker->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Worker not found'
        );
    }
}
