<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Support\Medical\MedicalEvidence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Medical\MedicalQcDecisionRequest;
use App\Models\Purchase\PurchaseMedicalBulkBatch;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Services\Medical\MedicalCertificatePdfService;
use App\Services\Medical\MedicalReportService;
use App\Services\Purchase\PurchaseMedicalWorkflowService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Purchase\PurchaseMedicalFitness;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The Purchase Medical Fitness register and its quality check — the mirror of
 * the TPV controller, against the Purchase register.
 */
class PurchaseMedicalController extends Controller
{
    public function __construct(
        private PurchaseMedicalWorkflowService $workflow,
        private MedicalCertificatePdfService $pdf,
    ) {}

    /* ── Register ───────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        $tid = $request->user()->tenant_id;

        $rows = PurchaseWorkerMedical::forTenant($tid)
            ->with([
                'worker:id,full_name,worker_code,purchase_vendor_id,designation',
                'worker.vendor:id,company_name',
                'doctor:id,name',
                'reviewer:id,name',
            ])
            ->when($request->query('fitness_status'), fn ($q, $s) => $q->where('fitness_status', $s))
            ->when($request->query('qc_status'), fn ($q, $s) => $q->where('qc_status', $s))
            ->when($request->query('origin'), fn ($q, $o) => $q->where('origin', $o))
            ->when($request->query('certificate'), fn ($q, $c) => $q->where('certificate_no', $c))
            ->when($request->query('vendor_id'), fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->when($request->query('expiry') === 'expired', fn ($q) => $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()))
            ->when($request->query('expiry') === 'expiring', fn ($q) => $q->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', now())->whereDate('expiry_date', '<=', now()->addDays(30)))
            ->latest('exam_date')
            ->limit(2000)
            ->get();

        $summary = [
            'total'   => $rows->count(),
            'fit'     => $rows->where('fitness_status', PurchaseMedicalFitness::FIT)->count(),
            'unfit'   => $rows->where('fitness_status', PurchaseMedicalFitness::UNFIT)->count(),
            'pending' => $rows->where('fitness_status', PurchaseMedicalFitness::PENDING)->count(),
            'expired' => $rows->filter(fn ($m) => $m->is_expired)->count(),
            'awaiting_review' => $rows->where('qc_status', MedicalQcStatus::PENDING)->count(),
            'on_hold'         => $rows->where('qc_status', MedicalQcStatus::HOLD)->count(),
            'rejected'        => $rows->where('qc_status', MedicalQcStatus::REJECTED)->count(),
            'approved'        => $rows->where('qc_status', MedicalQcStatus::APPROVED)->count(),
        ];

        return response()->json([
            'data'     => $rows,
            'summary'  => $summary,
            'statuses' => PurchaseMedicalFitness::ALL,
            'qc'       => $this->vocabulary($tid),
        ]);
    }

    public function show(Request $request, int $medical)
    {
        $record = $this->find($request, $medical);

        return response()->json([
            'data' => [
                'medical'  => $record->load(['worker:id,full_name,worker_code,purchase_vendor_id', 'worker.vendor:id,company_name', 'doctor:id,name', 'reviewer:id,name']),
                'timeline' => $this->workflow->timeline($record),
                'qc'       => $this->vocabulary($request->user()->tenant_id),
            ],
        ]);
    }

    /* ── Quality check ──────────────────────────────────────────────────── */

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

    public function storeExternal(Request $request, int $worker)
    {
        $subject = PurchaseWorker::forTenant($request->user()->tenant_id)->find($worker);
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

    public function template(Request $request)
    {
        $template = PurchaseMedicalWorkflowService::template();
        $format   = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        return Spreadsheet::download(
            [$template['headers'], $template['sample']],
            'medical-certificates-template',
            $format,
        );
    }

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

    public function batches(Request $request)
    {
        $rows = PurchaseMedicalBulkBatch::forTenant($request->user()->tenant_id)
            ->with(['uploader:id,name', 'vendor:id,company_name'])
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows]);
    }

    /* ── Documents ──────────────────────────────────────────────────────── */

    public function certificate(Request $request, int $medical)
    {
        $record = $this->find($request, $medical);
        $record->loadMissing('worker.vendor');

        return $this->pdf->render($record, [
            'worker_name' => $record->worker?->full_name,
            'worker_code' => $record->worker?->worker_code,
            'vendor_name' => $record->worker?->vendor?->company_name,
            'designation' => $record->worker?->designation,
            'dob'         => optional($record->worker?->dob)->format('d M Y'),
            'gender'      => $record->worker?->gender,
        ])->stream(($record->certificate_no ?: 'medical-certificate').'.pdf');
    }

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
            // Filterable by project and by employee, as the brief asked.
            'project'   => 'nullable|string|max:191',
            'worker_id' => 'nullable|integer',
        ]);

        return response()->json([
            'data' => $reports->build('purchase', $request->user()->tenant_id, $data),
        ]);
    }

    /** The vendor table as a spreadsheet — the sheet people take to a meeting. */
    public function reportExport(Request $request, MedicalReportService $reports)
    {
        $data = $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date|after_or_equal:from',
            'vendor_id' => 'nullable|integer',
            // Filterable by project and by employee, as the brief asked.
            'project'   => 'nullable|string|max:191',
            'worker_id' => 'nullable|integer',
            'format'    => 'nullable|in:csv,xlsx',
        ]);

        $report = $reports->build('purchase', $request->user()->tenant_id, $data);

        return Spreadsheet::download(
            $reports->vendorRows($report),
            'medical-report-purchase-'.now()->toDateString(),
            ($data['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv',
        );
    }

    /* ── Worker view ────────────────────────────────────────────────────── */

    public function workerHistory(Request $request, int $worker)
    {
        $subject = PurchaseWorker::forTenant($request->user()->tenant_id)->find($worker);
        abort_unless($subject, 404, 'Worker not found.');

        return response()->json(['data' => $this->workflow->history($subject)]);
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    private function find(Request $request, int $id): PurchaseWorkerMedical
    {
        $record = PurchaseWorkerMedical::forTenant($request->user()->tenant_id)->find($id);

        abort_unless($record, 404, 'Certificate not found.');

        return $record;
    }

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
    /**
     * The signature or camera photo taken at the examination.
     *
     * Both moved off the publicly-served disk (see MedicalEvidence): they are
     * the proof the doctor was with that person, and a folder the web server
     * hands to anyone who asks is no place for it. Served here instead, behind
     * the same tenant check every other read on this record goes through.
     */
    public function evidence(Request $request, int $medical, string $kind)
    {
        abort_unless(in_array($kind, ['signature', 'capture'], true), 404);

        $record = $this->find($request, $medical);
        $path   = $kind === 'signature' ? $record->signature_path : $record->capture_photo_path;

        abort_unless($path && MedicalEvidence::isSafe($path), 404, 'Nothing on this record.');

        $disk = MedicalEvidence::diskFor($path);
        abort_unless($disk, 404, 'The file is no longer on disk.');

        return response()->file(Storage::disk($disk)->path($path), [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

}
