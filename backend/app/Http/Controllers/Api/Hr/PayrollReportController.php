<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\PayrollReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll Reports & Analytics (Payroll Phase 6) — read-only, tenant-scoped,
 * HR-permission gated (payroll figures are sensitive). Delegates to
 * PayrollReportService; never writes or recalculates payroll.
 */
class PayrollReportController extends Controller
{
    public function __construct(private PayrollReportService $service)
    {
    }

    public function summary(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->summary($this->tenant($request), $this->filters($request)));
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

    public function components(Request $request)
    {
        $this->gate($request);
        return response()->json($this->service->components($this->tenant($request), $this->filters($request)));
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

    /** CSV (Excel) or PDF export of a report. report=summary|departments|components, format=csv|pdf. */
    public function export(Request $request)
    {
        $this->gate($request);
        $report = $request->query('report', 'summary');
        $format = $request->query('format', 'csv');
        $data   = $this->service->exportRows($report, $this->tenant($request), $this->filters($request));
        $base   = str_replace(' ', '_', strtolower($data['title']));

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('pdf.payroll_report', ['data' => $data])->setPaper('a4', 'landscape');
            return $pdf->download($base.'.pdf');
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
            'year'        => $request->query('year'),
            'month'       => $request->query('month'),
            'department'  => $request->query('department'),
            'designation' => $request->query('designation'),
            'employee_id' => $request->query('employee_id'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== 'All');
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to view payroll reports');
    }
}
