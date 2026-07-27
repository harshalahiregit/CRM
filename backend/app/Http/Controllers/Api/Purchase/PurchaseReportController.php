<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Services\Purchase\PurchaseReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase Reports — read-only aggregations for the Reports screen. Four
 * table reports and two chart reports, all filtered by a Period. Purchase-owned
 * data only (purchase_* tables, purchase_vendor_id); no TPV, no shared Vendor.
 */
class PurchaseReportController extends Controller
{
    public function __construct(private PurchaseReportService $reports)
    {
    }

    public function itemCost(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to) => $this->reports->itemCost($tid, $from, $to));
    }

    public function poVoucher(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to) => $this->reports->poVoucher($tid, $from, $to));
    }

    public function orders(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to) => $this->reports->orders($tid, $from, $to));
    }

    public function invoices(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to) => $this->reports->invoices($tid, $from, $to));
    }

    /** Chart: purchase statistics by number of purchase orders. */
    public function statsByCount(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to) => $this->reports->orderStats($tid, $from, $to, 'count'));
    }

    /** Chart: purchase statistics by cost. */
    public function statsByCost(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to) => $this->reports->orderStats($tid, $from, $to, 'cost'));
    }

    /** Validate the period, resolve its window, and hand off to the service. */
    private function run(Request $request, callable $fn)
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(PurchaseReportService::PERIODS)],
        ]);

        [$from, $to] = $this->reports->resolvePeriod($data['period'] ?? 'all_time');

        return response()->json([
            'period' => $data['period'] ?? 'all_time',
            'from'   => $from,
            'to'     => $to,
            ...$fn($request->user()->tenant_id, $from, $to),
        ]);
    }
}
