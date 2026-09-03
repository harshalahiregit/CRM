<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medical\MedicalQcDecisionRequest;
use App\Models\Tpv\TpvMedicalBulkBatch;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Services\Medical\MedicalCertificatePdfService;
use App\Services\Medical\MedicalReportService;
use App\Services\Tpv\TpvMedicalWorkflowService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Spreadsheet;
use App\Support\Tpv\TpvMedicalFitness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The Medical Fitness register and the quality check that sits on top of it
 * (Sangoe TPV §3/§16 + the Medical module).
 *
 * The register was read-only. It is now also where the quality team works: the
 * pending queue, the Approve / Reject / Hold verdict with its mandatory reason,
 * the back-and-forth timeline with the vendor, and the bulk intake of external
 * certificates.
 */
class TpvMedicalController extends Controller
{
    public function __construct(
        private TpvMedicalWorkflowService $workflow,
        private MedicalCertificatePdfService $pdf,
    ) {}

    /* ── Register ───────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        $tid = $request->user()->tenant_id;

        $rows = TpvWorkerMedical::where('tenant_id', $tid)
            ->with([
                'worker:id,name,worker_code,vendor_id,designation',
                'worker.vendor:id,company_name',
                'doctor:id,name',
                'reviewer:id,name',
            ])
            ->when($request->query('fitness_status'), fn ($q, $s) => $q->where('fitness_status', $s))
            ->when($request->query('qc_status'), fn ($q, $s) => $q->where('qc_status', $s))
            ->when($request->query('origin'), fn ($q, $o) => $q->where('origin', $o))
            ->when($request->query('certificate'), fn ($q, $c) => $q->where('certificate_no', $c))
            ->when($request->query('vendor_id'), fn ($q, $v) => $q->whereHas('worker', fn ($w) => $w->where('vendor_id', $v)))
            ->when($request->query('expiry') === 'expired', fn ($q) => $q->whereNotNull('valid_until')->whereDate('valid_until', '<', now()))
            ->when($request->query('expiry') === 'expiring', fn ($q) => $q->whereNotNull('valid_until')
                ->whereDate('valid_until', '>=', now())->whereDate('valid_until', '<=', now()->addDays(30)))
            ->latest('exam_date')
            ->limit(2000)
            ->get();

        $summary = [
            'total'    => $rows->count(),
            'fit'      => $rows->where('fitness_status', TpvMedicalFitness::FIT)->count(),
            'unfit'    => $rows->where('fitness_status', TpvMedicalFitness::UNFIT)->count(),
            'pending'  => $rows->where('fitness_status', TpvMedicalFitness::PENDING)->count(),
            'expired'  => $rows->filter(fn ($m) => $m->is_expired)->count(),
            // The quality-check queue — what the reviewer actually works from.
            'awaiting_review' => $rows->where('qc_status', MedicalQcStatus::PENDING)->count(),
            'on_hold'         => $rows->where('qc_status', MedicalQcStatus::HOLD)->count(),
            'rejected'        => $rows->where('qc_status', MedicalQcStatus::REJECTED)->count(),
            'approved'        => $rows->where('qc_status', MedicalQcStatus::APPROVED)->count(),
        ];

        return response()->json([
            'data'      => $rows,
            'summary'   => $summary,
            'statuses'  => TpvMedicalFitness::ALL,
            'qc'        => $this->vocabulary($tid),
        ]);
    }

    /** One certificate with its complete communication history. */
    public function show(Request $request, int $medical)
    {
        $record = $this->find($request, $medical);

        return response()->json([
            'data' => [
                'medical'  => $record->load(['worker:id,name,worker_code,vendor_id', 'worker.vendor:id,company_name', 'doctor:id,name', 'reviewer:id,name']),
                'timeline' => $this->workflow->timeline($record),
                'qc'       => $this->vocabulary($request->user()->tenant_id),
            ],
        ]);
    }

    /* ── Quality check ──────────────────────────────────────────────────── */

    /** Approve, reject or hold. The reason is enforced by the form request. */
    public function decide(MedicalQcDecisionRequest $request, int $medical)
    {
        $record = $this->find($request, $medical);
        $data   = $request->validated();

        $updated = $this->workflow->decide($record, $data['decision'], [
            'reason_code' => $data['reason_code'] ?? null,
            'note'        => $data['note'] ?? null,
        ], $request->user());

        return response()->json([
            'message' => 'Certificate '.strtolower(MedicalQcStatus::label($data['decision'])).'.',
            'data'    => $updated,
        ]);
    }

    /** A remark on the current round, in either direction. */
    public function comment(Request $request, int $medical)
    {
        $data = $request->validate([
            'body'       => 'required|string|max:4000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $record  = $this->find($request, $medical);
        $message = $this->workflow->comment($record, $data['body'], $request->file('attachment'), $request->user());

        return response()->json(['message' => 'Comment added.', 'data' => $message], 201);
    }

    /* ── External certificates ──────────────────────────────────────────── */

    /** Record one external certificate against a worker (admin side). */
    public function storeExternal(Request $request, int $worker)
    {
        $subject = TpvWorker::forTenant($request->user()->tenant_id)->find($worker);
        abort_unless($subject, 404, 'Worker not found.');

        $data = $request->validate([
            'exam_date'         => 'nullable|date|before_or_equal:today',
            'valid_until'       => 'nullable|date|after_or_equal:exam_date',
            'fitness_status'    => 'required|string|max:40',
            'examiner_name'     => 'nullable|string|max:120',
            'doctor_license_no' => 'nullable|string|max:60',
            'clinic_name'       => 'nullable|string|max:160',
            'blood_group'       => 'nullable|string|max:8',
            'restrictions'      => 'nullable|string|max:2000',
            'doctor_remarks'    => 'nullable|string|max:5000',
            'report_file'       => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $data['report_file'] = $request->file('report_file');

        $medical = $this->workflow->record($subject, $data, $request->user(), MedicalWorkflow::ORIGIN_VENDOR_UPLOAD);

        return response()->json(['message' => 'External certificate recorded.', 'data' => $medical], 201);
    }

    /** The bulk template, as a real .csv or .xlsx download. */
    public function template(Request $request)
    {
        $template = TpvMedicalWorkflowService::template();
        $format   = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        return Spreadsheet::download(
            [$template['headers'], $template['sample']],
            'medical-certificates-template',
            $format,
        );
    }

    /** Import a sheet of external certificates. */
    public function bulkUpload(Request $request)
    {
        $request->validate([
            'file'           => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'vendor_id'      => 'nullable|integer',
            'certificates'   => 'nullable|array|max:200',
            'certificates.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $batch = $this->workflow->bulkImport(
            $request->file('file'),
            $request->input('vendor_id') ? (int) $request->input('vendor_id') : null,
            $request->user(),
            MedicalWorkflow::SIDE_ADMIN,
            $request->file('certificates') ?? [],
        );

        return response()->json([
            'message' => "{$batch->created_count} certificate(s) imported, {$batch->failed_count} rejected.",
            'data'    => $batch,
        ], 201);
    }

    /** Past imports, so a rejected row can be traced back to its file. */
    public function batches(Request $request)
    {
        $rows = TpvMedicalBulkBatch::forTenant($request->user()->tenant_id)
            ->with(['uploader:id,name', 'vendor:id,company_name'])
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows]);
    }

    /* ── Documents ──────────────────────────────────────────────────────── */

    /** The generated prescription. */
    public function certificate(Request $request, int $medical)
    {
        $record = $this->find($request, $medical);
        $record->loadMissing('worker.vendor');

        return $this->pdf->render($record, [
            'worker_name' => $record->worker?->name,
            'worker_code' => $record->worker?->worker_code,
            'vendor_name' => $record->worker?->vendor?->company_name,
            'designation' => $record->worker?->designation,
            'dob'         => optional($record->worker?->dob)->format('d M Y'),
            'gender'      => $record->worker?->gender,
        ])->stream(($record->certificate_no ?: 'medical-certificate').'.pdf');
    }

    /** The uploaded evidence behind an external certificate. */
    public function document(Request $request, int $medical)
    {
        $record = $this->find($request, $medical);
        abort_unless($record->document_path && Storage::disk('local')->exists($record->document_path), 404, 'No document on this record.');

        return Storage::disk('local')->download($record->document_path);
    }

    /* ── Reporting ──────────────────────────────────────────────────────── */

    /**
     * The Medical report: volume, vendor statistics, successes and failures,
     * health ratings, rejection reasons and review turnaround.
     */
    public function report(Request $request, MedicalReportService $reports)
    {
        $data = $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date|after_or_equal:from',
            'vendor_id' => 'nullable|integer',
        ]);

        return response()->json([
            'data' => $reports->build('tpv', $request->user()->tenant_id, $data),
        ]);
    }

    /** The vendor table as a spreadsheet — the sheet people take to a meeting. */
    public function reportExport(Request $request, MedicalReportService $reports)
    {
        $data = $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date|after_or_equal:from',
            'vendor_id' => 'nullable|integer',
            'format'    => 'nullable|in:csv,xlsx',
        ]);

        $report = $reports->build('tpv', $request->user()->tenant_id, $data);

        return Spreadsheet::download(
            $reports->vendorRows($report),
            'medical-report-tpv-'.now()->toDateString(),
            ($data['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv',
        );
    }

    /* ── Worker view ────────────────────────────────────────────────────── */

    /** A worker's health history and score, for their profile. */
    public function workerHistory(Request $request, int $worker)
    {
        $subject = TpvWorker::forTenant($request->user()->tenant_id)->find($worker);
        abort_unless($subject, 404, 'Worker not found.');

        return response()->json(['data' => $this->workflow->history($subject)]);
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    private function find(Request $request, int $id): TpvWorkerMedical
    {
        $record = TpvWorkerMedical::forTenant($request->user()->tenant_id)->find($id);

        abort_unless($record, 404, 'Certificate not found.');

        return $record;
    }

    /** The verdicts and reasons the review UI offers. */
    private function vocabulary(int $tenantId): array
    {
        $config = $this->workflow->config($tenantId);

        return [
            'statuses'       => MedicalQcStatus::ALL,
            'decisions'      => MedicalQcStatus::DECISIONS,
            'reasons'        => $config['reasons'] ?? MedicalQcStatus::defaultReasons(),
            'max_iterations' => (int) ($config['max_iterations'] ?? 10),
            'origins'        => MedicalWorkflow::ORIGIN_LABELS,
        ];
    }
}
