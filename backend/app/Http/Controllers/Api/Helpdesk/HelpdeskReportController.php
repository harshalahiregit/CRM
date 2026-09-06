<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskReportService;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;

/**
 * The Help Desk report — the module's own Report section.
 *
 * Separate from the analytics dashboard: that answers "what needs attention
 * now", while this is pointed at a range, narrowed, and exported.
 */
class HelpdeskReportController extends Controller
{
    private const FILTERS = [
        'from'          => 'nullable|date',
        'to'            => 'nullable|date|after_or_equal:from',
        'status'        => 'nullable|string|max:40',
        'priority'      => 'nullable|string|max:40',
        'assigned_to'   => 'nullable|integer',
        'department_id' => 'nullable|integer',
    ];

    public function index(Request $request, HelpdeskReportService $reports)
    {
        return response()->json([
            'data' => $reports->build($request->user()->tenant_id, $request->validate(self::FILTERS)),
        ]);
    }

    /** The agent table as a spreadsheet. */
    public function export(Request $request, HelpdeskReportService $reports)
    {
        $data = $request->validate(self::FILTERS + ['format' => 'nullable|in:csv,xlsx']);
        $report = $reports->build($request->user()->tenant_id, $data);

        $rows = [['Agent', 'Tickets', 'Resolved', 'Open', 'SLA breached', 'Avg first response (min)', 'Avg resolution (min)']];
        foreach ($report['by_agent'] as $r) {
            $rows[] = [
                $r['agent'], $r['tickets'], $r['resolved'], $r['open'], $r['sla_breached'],
                $r['avg_response_mins'] ?? '', $r['avg_resolution_mins'] ?? '',
            ];
        }

        return Spreadsheet::download(
            $rows,
            'helpdesk-report-'.now()->toDateString(),
            ($data['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv',
        );
    }
}
