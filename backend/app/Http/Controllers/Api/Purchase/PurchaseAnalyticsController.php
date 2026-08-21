<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Services\Purchase\PurchaseAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Reports & Analytics — mirror of the TPV governance analytics (parity). */
class PurchaseAnalyticsController extends Controller
{
    public function __construct(private PurchaseAnalyticsService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $months = (int) $request->integer('months', 6);

        return response()->json([
            'overview'  => $this->service->overview($tenantId),
            'trends'    => $this->service->trends($tenantId, $months),
            'benchmark' => $this->service->benchmark($tenantId),
            'datasets'  => PurchaseAnalyticsService::DATASETS,
        ]);
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'dataset' => ['required', Rule::in(PurchaseAnalyticsService::DATASETS)],
        ]);

        [$name, $header, $rows] = $this->service->export($request->user()->tenant_id, $data['dataset']);
        $csv = $this->service->toCsv($header, $rows);
        $filename = $name.'-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
