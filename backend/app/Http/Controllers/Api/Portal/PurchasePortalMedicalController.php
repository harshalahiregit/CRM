<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseMedicalBulkBatch;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Services\Medical\MedicalCertificatePdfService;
use App\Services\Purchase\PurchaseMedicalWorkflowService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The External Medical Flow, vendor side (Purchase) — the mirror of the TPV
 * portal controller, scoped to the authenticated PurchaseVendor.
 */
class PurchasePortalMedicalController extends Controller
{
    public function __construct(
        private PurchaseMedicalWorkflowService $workflow,
        private MedicalCertificatePdfService $pdf,
    ) {}

    public function index(Request $request)
    {
        $vendor = $this->vendor($request);

        $rows = PurchaseWorkerMedical::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->with(['worker:id,full_name,worker_code', 'doctor:id,name'])
            ->when($request->query('qc_status'), fn ($q, $s) => $q->where('qc_status', $s))
            ->orderByDesc('exam_date')->orderByDesc('id')
            ->limit(1000)->get();

        $workers = PurchaseWorker::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->with('latestMedical')
            ->orderBy('full_name')->limit(1000)->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'name' => $w->full_name, 'worker_code' => $w->worker_code,
                'designation' => $w->designation,
                'clearance' => $this->workflow->clearanceFor($w),
            ]);

        return response()->json([
            'data'    => $rows,
            'workers' => $workers,
            'summary' => [
                'total'           => $rows->count(),
                'awaiting_review' => $rows->where('qc_status', MedicalQcStatus::PENDING)->count(),
                'on_hold'         => $rows->where('qc_status', MedicalQcStatus::HOLD)->count(),
                'rejected'        => $rows->where('qc_status', MedicalQcStatus::REJECTED)->count(),
                'approved'        => $rows->where('qc_status', MedicalQcStatus::APPROVED)->count(),
                'blocked_workers' => $workers->where('clearance.cleared', false)->count(),
            ],
            'template' => PurchaseMedicalWorkflowService::template(),
        ]);
    }

    public function show(Request $request, int $medical)
    {
        $record = $this->owned($request, $medical);

        return response()->json([
            'data' => [
                'medical'  => $record->load('worker:id,full_name,worker_code'),
                'timeline' => $this->workflow->timeline($record),
            ],
        ]);
    }

    public function store(Request $request, int $worker)
    {
        $vendor  = $this->vendor($request);
        $subject = PurchaseWorker::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)->find($worker);
        abort_unless($subject, 404, 'Worker not found.');

        $data = $request->validate([
            'exam_date'         => 'nullable|date|before_or_equal:today',
            'valid_until'       => 'nullable|date|after_or_equal:exam_date',
            'fitness_status'    => 'required|string|max:40',
            'examiner_name'     => 'required|string|max:120',
            'doctor_license_no' => 'nullable|string|max:60',
            'clinic_name'       => 'nullable|string|max:160',
            'blood_group'       => 'nullable|string|max:8',
            'restrictions'      => 'nullable|string|max:2000',
            'doctor_remarks'    => 'nullable|string|max:5000',
            'report_file'       => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $data['report_file'] = $request->file('report_file');

        $medical = $this->workflow->record($subject, $data, $vendor, MedicalWorkflow::ORIGIN_VENDOR_UPLOAD);

        return response()->json(['message' => 'Certificate uploaded for quality check.', 'data' => $medical], 201);
    }

    public function template(Request $request)
    {
        $template = PurchaseMedicalWorkflowService::template();

        return Spreadsheet::download(
            [$template['headers'], $template['sample']],
            'medical-certificates-template',
            $request->query('format') === 'xlsx' ? 'xlsx' : 'csv',
        );
    }

    public function bulkUpload(Request $request)
    {
        $vendor = $this->vendor($request);

        $request->validate([
            'file'           => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'certificates'   => 'nullable|array|max:200',
            'certificates.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $batch = $this->workflow->bulkImport(
            $request->file('file'),
            $vendor->id,
            $vendor,
            MedicalWorkflow::SIDE_VENDOR,
            $request->file('certificates') ?? [],
        );

        return response()->json([
            'message' => "{$batch->created_count} certificate(s) uploaded, {$batch->failed_count} rejected.",
            'data'    => $batch,
        ], 201);
    }

    public function batches(Request $request)
    {
        $vendor = $this->vendor($request);

        $rows = PurchaseMedicalBulkBatch::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows]);
    }

    public function resubmit(Request $request, int $medical)
    {
        $record = $this->owned($request, $medical);

        $data = $request->validate([
            'message'        => 'nullable|string|max:4000',
            'exam_date'      => 'nullable|date|before_or_equal:today',
            'valid_until'    => 'nullable|date',
            'fitness_status' => 'nullable|string|max:40',
            'examiner_name'  => 'nullable|string|max:120',
            'doctor_license_no' => 'nullable|string|max:60',
            'clinic_name'    => 'nullable|string|max:160',
            'restrictions'   => 'nullable|string|max:2000',
            'report_file'    => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $data['report_file'] = $request->file('report_file');

        $updated = $this->workflow->resubmit($record, $data, $this->vendor($request));

        return response()->json(['message' => 'Certificate resubmitted.', 'data' => $updated]);
    }

    public function comment(Request $request, int $medical)
    {
        $data = $request->validate([
            'body'       => 'required|string|max:4000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $record  = $this->owned($request, $medical);
        $message = $this->workflow->comment($record, $data['body'], $request->file('attachment'), $this->vendor($request));

        return response()->json(['message' => 'Reply sent.', 'data' => $message], 201);
    }

    public function certificate(Request $request, int $medical)
    {
        $record = $this->owned($request, $medical);
        $record->loadMissing('worker.vendor');

        return $this->pdf->render($record, [
            'worker_name' => $record->worker?->full_name,
            'worker_code' => $record->worker?->worker_code,
            'vendor_name' => $record->worker?->vendor?->company_name,
            'designation' => $record->worker?->designation,
        ])->stream(($record->certificate_no ?: 'medical-certificate').'.pdf');
    }

    public function document(Request $request, int $medical)
    {
        $record = $this->owned($request, $medical);
        abort_unless($record->document_path && Storage::disk('local')->exists($record->document_path), 404, 'No document on this record.');

        return Storage::disk('local')->download($record->document_path);
    }

    /* ── Ownership ──────────────────────────────────────────────────────── */

    private function vendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $vendor;
    }

    private function owned(Request $request, int $id): PurchaseWorkerMedical
    {
        $vendor = $this->vendor($request);

        $record = PurchaseWorkerMedical::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->find($id);

        abort_unless($record, 404, 'Certificate not found.');

        return $record;
    }
}
