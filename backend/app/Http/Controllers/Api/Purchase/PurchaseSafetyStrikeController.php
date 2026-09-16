<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\IssuePurchaseSafetyStrikeRequest;
use App\Models\Purchase\PurchaseSafetyStrike;
use App\Models\Purchase\PurchaseWorker;
use App\Services\Purchase\PurchaseSafetyStrikeService;
use Illuminate\Http\Request;

/**
 * Safety strikes against a Purchase vendor's workers.
 *
 * The mirror of TpvSafetyStrikeController, action for action. Reading is open
 * to staff; issuing and voiding are admin authority, because the third strike
 * ends somebody's site access and an appeal decides whether it stands.
 */
class PurchaseSafetyStrikeController extends Controller
{
    public function __construct(private PurchaseSafetyStrikeService $strikes) {}

    /** The whole tenant's strike ledger; `vendor_id` narrows it to one crew. */
    public function index(Request $request)
    {
        return response()->json(
            $this->strikes->list(
                $request->user()->tenant_id,
                $request->only(['severity', 'active', 'worker_id', 'vendor_id']),
            ),
        );
    }

    public function forWorker(Request $request, PurchaseWorker $worker)
    {
        $this->assertWorkerTenant($request, $worker);

        return response()->json([
            'strikes'      => $this->strikes->listForWorker($worker),
            'active_count' => $this->strikes->activeCount($worker),
        ]);
    }

    /** Issue a strike. May auto-terminate the worker — the response says so. */
    public function store(IssuePurchaseSafetyStrikeRequest $request, PurchaseWorker $worker)
    {
        $this->assertWorkerTenant($request, $worker);

        return response()->json(
            $this->strikes->issue($worker, $request->validated(), $request->user()),
            201,
        );
    }

    /** Void a strike on appeal — never auto-restores access. */
    public function void(Request $request, PurchaseSafetyStrike $strike)
    {
        $this->assertStrikeTenant($request, $strike);

        $data = $request->validate(['reason' => 'required|string|max:255']);

        return response()->json($this->strikes->void($strike, $request->user(), $data['reason']));
    }

    public function stats(Request $request)
    {
        return response()->json($this->strikes->stats($request->user()->tenant_id));
    }

    /** A worker in another workspace is not theirs to know about, so 404. */
    private function assertWorkerTenant(Request $request, PurchaseWorker $worker): void
    {
        abort_unless((int) $worker->tenant_id === (int) $request->user()->tenant_id, 404, 'Worker not found');
    }

    private function assertStrikeTenant(Request $request, PurchaseSafetyStrike $strike): void
    {
        abort_unless((int) $strike->tenant_id === (int) $request->user()->tenant_id, 404, 'Strike not found');
    }
}
