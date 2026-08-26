<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Services\Tpv\TpvAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Reports & Analytics (Sangoe TPV §33). Read-only, tenant-scoped. */
class TpvAnalyticsController extends Controller
{
    public function __construct(private TpvAnalyticsService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $months = (int) $request->integer('months', 6);

        return response()->json([
            'overview'  => $this->service->overview($tenantId),
            'trends'    => $this->service->trends($tenantId, $months),
            'benchmark' => $this->service->benchmark($tenantId),
            'datasets'  => TpvAnalyticsService::DATASETS,
        ]);
    }

    /** §33 — the Reports hub: the doc's named reports enumerated as a catalogue. */
    public function reports(Request $request)
    {
        return response()->json([
            'reports'  => $this->service->catalogue(),
            'datasets' => TpvAnalyticsService::DATASETS,
        ]);
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'dataset' => ['required', Rule::in(TpvAnalyticsService::DATASETS)],
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
