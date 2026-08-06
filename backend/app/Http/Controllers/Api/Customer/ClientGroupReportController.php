<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\ClientGroupReportService;
use App\Support\Spreadsheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Group-wise customer reporting: one group in detail, every group compared, and
 * the same figures as a CSV/XLSX or PDF download.
 *
 * `group_id` is optional everywhere — omitting it reports across ALL customers,
 * which is what the screen opens on.
 */
class ClientGroupReportController extends Controller
{
    public function __construct(private ClientGroupReportService $reports)
    {
    }

    private function range(Request $request): array
    {
        $data = $request->validate([
            'group_id' => 'nullable|integer',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date|after_or_equal:from',
        ]);

        return [$data['group_id'] ?? null, $data['from'] ?? null, $data['to'] ?? null];
    }

    /** Detail for one group (or all customers). */
    public function show(Request $request)
    {
        [$groupId, $from, $to] = $this->range($request);

        return response()->json($this->reports->groupReport($request->user()->tenant_id, $groupId, $from, $to));
    }

    /** Every group side by side, plus the ungrouped remainder. */
    public function comparison(Request $request)
    {
        [, $from, $to] = $this->range($request);

        return response()->json($this->reports->allGroups($request->user()->tenant_id, $from, $to));
    }

    /** Per-customer rows as csv|xlsx, matching the customers-export convention. */
    public function export(Request $request): Response
    {
        [$groupId, $from, $to] = $this->range($request);
        $format = strtolower($request->query('format', 'csv')) === 'xlsx' ? 'xlsx' : 'csv';

        $report = $this->reports->groupReport($request->user()->tenant_id, $groupId, $from, $to);

        $rows = [[
            'Customer', 'Active', 'Invoices', 'Billed', 'Paid', 'Outstanding', 'Credit',
            'GST Total', 'GST Paid', 'GST Unpaid', 'TDS',
            'Current', '1-30d', '31-60d', '61-90d', '90d+',
            'Proposals', 'Estimates', 'Contracts', 'Tickets', 'Open Tickets', 'Projects',
        ]];

        foreach ($report['clients'] as $c) {
            $rows[] = [
                $c['company'], $c['active'] ? 'Yes' : 'No', $c['invoice_count'],
                $c['total_billed'], $c['total_paid'], $c['outstanding'], $c['available_credit'],
                $c['gst_total'], $c['gst_paid'], $c['gst_unpaid'], $c['tds_deducted'],
                $c['ageing']['current'], $c['ageing']['d30'], $c['ageing']['d60'],
                $c['ageing']['d90'], $c['ageing']['d90plus'],
                $c['activity']['proposals'], $c['activity']['estimates'], $c['activity']['contracts'],
                $c['activity']['tickets'], $c['activity']['open_tickets'], $c['activity']['projects'],
            ];
        }

        // Totals row, so the sheet reconciles without the reader re-summing.
        $t = $report['totals'];
        $rows[] = [
            'TOTAL ('.$t['customer_count'].' customers)', '', $t['invoice_count'],
            $t['total_billed'], $t['total_paid'], $t['outstanding'], $t['available_credit'],
            $t['gst_total'], $t['gst_paid'], $t['gst_unpaid'], $t['tds_deducted'],
            $t['ageing']['current'], $t['ageing']['d30'], $t['ageing']['d60'],
            $t['ageing']['d90'], $t['ageing']['d90plus'],
            $t['activity']['proposals'], $t['activity']['estimates'], $t['activity']['contracts'],
            $t['activity']['tickets'], $t['activity']['open_tickets'], $t['activity']['projects'],
        ];

        $slug = str_replace(' ', '-', strtolower($report['group']['name']));

        return Spreadsheet::download($rows, "group-report-{$slug}-".now()->format('Y-m-d').".{$format}", $format);
    }

    /** The full multi-section report as a PDF. */
    public function pdf(Request $request)
    {
        [$groupId, $from, $to] = $this->range($request);
        $tenantId = $request->user()->tenant_id;

        $report = $this->reports->groupReport($tenantId, $groupId, $from, $to);
        $slug   = str_replace(' ', '-', strtolower($report['group']['name']));

        $pdf = Pdf::loadView('pdf.customer-group-report', [
            'report'     => $report,
            'comparison' => $groupId ? null : $this->reports->allGroups($tenantId, $from, $to),
        ])->setPaper('a4', 'landscape');

        return $pdf->download("group-report-{$slug}-".now()->format('Y-m-d').'.pdf');
    }
}
