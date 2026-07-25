<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Hr\ExitReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exit Reports & Analytics (final Exit phase) — read-only, tenant-scoped,
 * HR-permission gated. Delegates to ExitReportService (no logic here). Export
 * reuses the Payroll/Leave Reports pattern: streamDownload CSV + the shared
 * pdf.payroll_report Blade. No new tables, no writes to any module.
 */
class ExitReportController extends Controller
{
    public function __construct(private ExitReportService $service)
    {
    }

    public function dashboard(Request $request)
    {
        $this->gate($request);
        $this->audit($request, 'Exit Report Viewed');

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

    public function exitTypes(Request $request)
    {
        $this->gate($request);

        return response()->json($this->service->exitTypes($this->tenant($request), $this->filters($request)));
    }

    public function settlements(Request $request)
    {
        $this->gate($request);

        return response()->json($this->service->settlements($this->tenant($request), $this->filters($request)));
    }

    public function clearances(Request $request)
    {
        $this->gate($request);

        return response()->json($this->service->clearances($this->tenant($request), $this->filters($request)));
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

    /** CSV or PDF export. report=employees|departments|exit-types|settlements|clearances|trends, format=csv|pdf. */
    public function export(Request $request)
    {
        $this->gate($request);
        $report = $request->query('report', 'employees');
        $format = $request->query('format', 'csv');
        $data   = $this->service->exportRows($report, $this->tenant($request), $this->filters($request));
        $base   = str_replace(' ', '_', strtolower($data['title']));

        $this->audit($request, 'Exit Report Exported', ['report' => $report, 'format' => $format]);
        Log::channel('hr')->info('Exit report exported', ['tenant_id' => $this->tenant($request), 'report' => $report, 'format' => $format]);

        if ($format === 'pdf') {
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

    /** Report activity is audited without a record subject (virtual ExitReport). */
    private function audit(Request $request, string $action, array $metadata = []): void
    {
        $actor = $request->user();
        AuditLog::create([
            'tenant_id'      => $this->tenant($request),
            'auditable_type' => 'ExitReport',
            'auditable_id'   => 0,
            'action'         => $action,
            'actor_id'       => $actor?->id,
            'actor_name'     => $actor?->name,
            'actor_role'     => $actor ? ($actor->internal_role ?: $actor->role) : null,
            'metadata'       => $metadata ?: null,
        ]);
    }

    private function filters(Request $request): array
    {
        return array_filter([
            'year'             => $request->query('year'),
            'month'            => $request->query('month'),
            'employee_id'      => $request->query('employee_id'),
            'department'       => $request->query('department'),
            'designation'      => $request->query('designation'),
            'exit_type_id'     => $request->query('exit_type_id'),
            'status'           => $request->query('status'),
            'settlement_month' => $request->query('settlement_month'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== 'All');
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to view exit reports');
    }
}
