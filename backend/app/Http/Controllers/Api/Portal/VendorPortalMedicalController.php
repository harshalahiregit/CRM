<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvMedicalBulkBatch;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Services\Medical\MedicalCertificatePdfService;
use App\Services\Tpv\TpvMedicalWorkflowService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The External Medical Flow, vendor side (TPV).
 *
 * A vendor whose workers were examined by their own doctor uploads those
 * certificates here — one at a time, or a whole sheet at once — and then holds
 * the conversation with the quality team about them: seeing why one was held,
 * replying, and sending a corrected certificate back.
 *
 * Every id is resolved through the caller's own vendor, so another vendor's
 * worker or certificate reads as absent (404), never as forbidden.
 */
class VendorPortalMedicalController extends Controller
{
    public function __construct(
        private TpvMedicalWorkflowService $workflow,
        private MedicalCertificatePdfService $pdf,
    ) {}

    /** Every certificate belonging to this vendor's workers, plus what is outstanding. */
    public function index(Request $request)
    {
        $vendor = $this->vendor($request);

        $rows = TpvWorkerMedical::forTenant($vendor->tenant_id)
            ->whereHas('worker', fn ($w) => $w->where('vendor_id', $vendor->id))
            ->with(['worker:id,name,worker_code,vendor_id', 'doctor:id,name'])
            ->when($request->query('qc_status'), fn ($q, $s) => $q->where('qc_status', $s))
            ->orderByDesc('exam_date')->orderByDesc('id')
            ->limit(1000)->get();

        // The vendor's real question is "who is still blocked?", so the worker
        // roster is answered alongside the certificates.
        $workers = TpvWorker::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->with('medical')
            ->orderBy('name')->limit(1000)->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'name' => $w->name, 'worker_code' => $w->worker_code,
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
            'template' => TpvMedicalWorkflowService::template(),
        ]);
    }

    /** One certificate and the full back-and-forth on it. */
    public function show(Request $request, int $medical)
    {
        $record = $this->owned($request, $medical);

        return response()->json([
            'data' => [
                'medical'  => $record->load('worker:id,name,worker_code'),
                'timeline' => $this->workflow->timeline($record),
            ],
        ]);
    }

    /** Upload one external doctor's certificate for one worker. */
    public function store(Request $request, int $worker)
    {
        $vendor  = $this->vendor($request);
        $subject = TpvWorker::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)->find($worker);
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
            // The certificate itself. Without it there is nothing to review.
            'report_file'       => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $data['report_file'] = $request->file('report_file');

        $medical = $this->workflow->record($subject, $data, $request->user(), MedicalWorkflow::ORIGIN_VENDOR_UPLOAD);

        return response()->json(['message' => 'Certificate uploaded for quality check.', 'data' => $medical], 201);
    }

    /** The sheet to fill in — the same columns the import reads. */
    public function template(Request $request)
    {
        $template = TpvMedicalWorkflowService::template();

        return Spreadsheet::download(
            [$template['headers'], $template['sample']],
            'medical-certificates-template',
            $request->query('format') === 'xlsx' ? 'xlsx' : 'csv',
        );
    }

    /** Upload a filled sheet, with the certificate files alongside it. */
    public function bulkUpload(Request $request)
    {
        $vendor = $this->vendor($request);

        $request->validate([
            'file'           => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'certificates'   => 'nullable|array|max:200',
            'certificates.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        // The vendor id comes from the session, never the payload — a vendor
        // cannot import certificates against somebody else's workers.
        $batch = $this->workflow->bulkImport(
            $request->file('file'),
            $vendor->id,
            $request->user(),
            MedicalWorkflow::SIDE_VENDOR,
            $request->file('certificates') ?? [],
        );

        return response()->json([
            'message' => "{$batch->created_count} certificate(s) uploaded, {$batch->failed_count} rejected.",
            'data'    => $batch,
        ], 201);
    }

    /** This vendor's own past imports, with the rejected rows. */
    public function batches(Request $request)
    {
        $vendor = $this->vendor($request);

        $rows = TpvMedicalBulkBatch::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows]);
    }

    /** Answer a hold and send the certificate back for review. */
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

        $updated = $this->workflow->resubmit($record, $data, $request->user());

        return response()->json(['message' => 'Certificate resubmitted.', 'data' => $updated]);
    }

    /** Reply on the timeline without resubmitting. */
    public function comment(Request $request, int $medical)
    {
        $data = $request->validate([
            'body'       => 'required|string|max:4000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $record  = $this->owned($request, $medical);
        $message = $this->workflow->comment($record, $data['body'], $request->file('attachment'), $request->user());

        return response()->json(['message' => 'Reply sent.', 'data' => $message], 201);
    }

    /** The certificate PDF for one of this vendor's workers. */
    public function certificate(Request $request, int $medical)
    {
        $record = $this->owned($request, $medical);
        $record->loadMissing('worker.vendor');

        return $this->pdf->render($record, [
            'worker_name' => $record->worker?->name,
            'worker_code' => $record->worker?->worker_code,
            'vendor_name' => $record->worker?->vendor?->company_name,
            'designation' => $record->worker?->designation,
        ])->stream(($record->certificate_no ?: 'medical-certificate').'.pdf');
    }

    /** The uploaded evidence, back to the vendor who filed it. */
    public function document(Request $request, int $medical)
    {
        $record = $this->owned($request, $medical);
        abort_unless($record->document_path && Storage::disk('local')->exists($record->document_path), 404, 'No document on this record.');

        return Storage::disk('local')->download($record->document_path);
    }

    /* ── Ownership ──────────────────────────────────────────────────────── */

    /** The vendor resolved from the token by the portal middleware. */
    private function vendor(Request $request)
    {
        $vendor = $request->attributes->get('portalVendor');
        abort_unless($vendor, 403, 'No vendor profile is linked to this login.');

        return $vendor;
    }

    /** A certificate on one of the caller's own workers, or 404. */
    private function owned(Request $request, int $id): TpvWorkerMedical
    {
        $vendor = $this->vendor($request);

        $record = TpvWorkerMedical::forTenant($vendor->tenant_id)
            ->whereHas('worker', fn ($w) => $w->where('vendor_id', $vendor->id))
            ->find($id);

        abort_unless($record, 404, 'Certificate not found.');

        return $record;
    }
}
