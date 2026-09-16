<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesReportService;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;

/**
 * The Sales report — the module's own Report section.
 *
 * Separate from the dashboard: the dashboard describes the current month and is
 * fixed, while this is pointed at a range, narrowed, and exported.
 */
class SalesReportController extends Controller
{
    private const FILTERS = [
        'from'      => 'nullable|date',
        'to'        => 'nullable|date|after_or_equal:from',
        'status'    => 'nullable|string|max:40',
        'client_id' => 'nullable|integer',
        'agent'     => 'nullable|string|max:191',
    ];

    public function index(Request $request, SalesReportService $reports)
    {
        return response()->json([
            'data' => $reports->build($request->user()->tenant_id, $request->validate(self::FILTERS)),
        ]);
    }

    /** The customer table as a spreadsheet — the sheet people take to a meeting. */
    public function export(Request $request, SalesReportService $reports)
    {
        $data = $request->validate(self::FILTERS + ['format' => 'nullable|in:csv,xlsx']);
        $report = $reports->build($request->user()->tenant_id, $data);

        $rows = [['Customer', 'Invoices', 'Billed', 'Paid', 'Outstanding', 'Overdue']];
        foreach ($report['by_customer'] as $r) {
            $rows[] = [$r['client'], $r['invoices'], $r['billed'], $r['paid'], $r['outstanding'], $r['overdue']];
        }

        return Spreadsheet::download(
            $rows,
            'sales-report-'.now()->toDateString(),
            ($data['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv',
        );
    }
}
