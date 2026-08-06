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
        return $this->run($request, fn ($tid, $from, $to, $f) => $this->reports->itemCost($tid, $from, $to, $f));
    }

    public function poVoucher(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to, $f) => $this->reports->poVoucher($tid, $from, $to, $f));
    }

    public function orders(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to, $f) => $this->reports->orders($tid, $from, $to, $f));
    }

    public function invoices(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to, $f) => $this->reports->invoices($tid, $from, $to, $f));
    }

    /** Chart: purchase statistics by number of purchase orders. */
    public function statsByCount(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to, $f) => $this->reports->orderStats($tid, $from, $to, 'count', $f));
    }

    /** Chart: purchase statistics by cost. */
    public function statsByCost(Request $request)
    {
        return $this->run($request, fn ($tid, $from, $to, $f) => $this->reports->orderStats($tid, $from, $to, 'cost', $f));
    }

    /** The values the filter controls offer, all drawn from real rows. */
    public function filters(Request $request)
    {
        return response()->json($this->reports->filterOptions($request->user()->tenant_id));
    }

    /** Validate the filters, resolve the period window, and hand off to the service. */
    private function run(Request $request, callable $fn)
    {
        $data = $request->validate([
            'period'      => ['nullable', Rule::in(PurchaseReportService::PERIODS)],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
            'currency'    => ['nullable', 'string', 'max:10'],
            'year'        => ['nullable', 'integer', 'min:1970', 'max:2200'],
            'items'       => ['nullable', 'array'],
            'items.*'     => ['string', 'max:255'],
        ]);

        $period = $data['period'] ?? 'all_time';
        [$from, $to] = $this->reports->resolvePeriod($period, $data['from'] ?? null, $data['to'] ?? null);

        return response()->json([
            'period' => $period,
            'from'   => $from,
            'to'     => $to,
            ...$fn($request->user()->tenant_id, $from, $to, $data),
        ]);
    }
}
