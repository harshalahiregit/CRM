<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\SalaryReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Enterprise Salary Reports — read-only, tenant-scoped, HR-permission gated (salary
 * figures are sensitive). Delegates to SalaryReportService; reuses the shared
 * pdf.payroll_report blade + CSV streamer. Never writes or recalculates.
 */
class SalaryReportController extends Controller
{
    public function __construct(private SalaryReportService $service)
    {
    }

    /** Report catalog + filter options for the report picker. */
    public function meta(Request $request)
    {
        $this->gate($request);

        return response()->json([
            'reports' => $this->service->reports(),
            'filters' => $this->service->filterOptions($this->tenant($request)),
        ]);
    }

    public function summary(Request $request)
    {
        $this->gate($request);

        return response()->json($this->service->summary($this->tenant($request), $this->filters($request)));
    }

    /** A single report's data: {title, columns, rows}. */
    public function show(Request $request, string $report)
    {
        $this->gate($request);

        return response()->json($this->service->build($report, $this->tenant($request), $this->filters($request)));
    }

    /** CSV (Excel) or PDF export of a report. */
    public function export(Request $request, string $report)
    {
        $this->gate($request);
        $format = $request->query('format', 'csv');
        $data = $this->service->exportRows($report, $this->tenant($request), $this->filters($request));
        $base = 'salary_'.str_replace(' ', '_', strtolower($data['title']));

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
            'department'   => $request->query('department'),
            'designation'  => $request->query('designation'),
            'grade_id'     => $request->query('grade_id'),
            'structure_id' => $request->query('structure_id'),
            'employee_id'  => $request->query('employee_id'),
            'type'         => $request->query('type'),
            'status'       => $request->query('status'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== 'All');
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to view salary reports');
    }
}
