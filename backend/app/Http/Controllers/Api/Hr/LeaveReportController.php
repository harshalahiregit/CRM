<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\LeaveReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leave Reports & Analytics (final Leave phase) — read-only, tenant-scoped,
 * HR-permission gated. Delegates to LeaveReportService (no logic here). Export
 * reuses the Payroll Reports pattern: streamDownload CSV + the shared
 * pdf.payroll_report Blade. No attendance data is produced or read.
 */
class LeaveReportController extends Controller
{
    public function __construct(private LeaveReportService $service)
    {
    }

    public function dashboard(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->dashboard($this->tenant($request)));
    }

    public function employees(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->employees($this->tenant($request), $this->filters($request)));
    }

    public function departments(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->departments($this->tenant($request), $this->filters($request)));
    }

    public function types(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->types($this->tenant($request), $this->filters($request)));
    }

    public function balances(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->balances($this->tenant($request), $this->filters($request)));
    }

    public function holidays(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->holidays($this->tenant($request), $this->filters($request)));
    }

    public function trends(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->trends($this->tenant($request), $this->filters($request)));
    }

    public function filterOptions(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->filterOptions($this->tenant($request)));
    }

    /** CSV (Excel) or PDF export. report=employees|departments|types|balances|holidays, format=csv|pdf. */
    public function export(Request $request)
    {
        $this->gate($request);
        $report = $request->query('report', 'employees');
        $format = $request->query('format', 'csv');
        $data   = $this->service->exportRows($report, $this->tenant($request), $this->filters($request));
        $base   = str_replace(' ', '_', strtolower($data['title']));

        Log::channel('hr')->info('Leave report exported', ['tenant_id' => $this->tenant($request), 'report' => $report, 'format' => $format]);

        if ($format === 'pdf') {
            // Reuses the generic Payroll Reports PDF view (title/headers/rows).
            return Pdf::loadView('pdf.payroll_report', ['data' => $data])->setPaper('a4', 'landscape')->download($base.'.pdf');
        }

        return $this->streamCsv($data, $base.'.csv');
    }

    private function streamCsv(array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        return array_filter([
            'year'          => $request->query('year'),
            'month'         => $request->query('month'),
            'employee_id'   => $request->query('employee_id'),
            'department'    => $request->query('department'),
            'designation'   => $request->query('designation'),
            'leave_type_id' => $request->query('leave_type_id'),
            'status'        => $request->query('status'),
            'holiday_type'  => $request->query('holiday_type'),
            'department_id' => $request->query('department_id'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== 'All');
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to view leave reports');
    }
}
