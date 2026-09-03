<?php

namespace App\Http\Controllers\Api\Medical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medical\SaveExaminationRequest;
use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\Vendor\Vendor;
use App\Services\Medical\MedicalCertificatePdfService;
use App\Services\Purchase\PurchaseMedicalWorkflowService;
use App\Services\Tpv\TpvMedicalWorkflowService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Tpv\TpvMedicalFitness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The doctor portal — the Internal Medical Flow.
 *
 * One login serves both vendor sides, which is why every route carries a
 * `{module}` segment: the doctor picks TPV or Purchase, then a vendor, then a
 * worker, then fills the examination form. The two sides keep their own tables
 * and their own workflow service; this controller is the seam that lets one
 * person work across both without either module learning about the other.
 *
 * A doctor sees workers, and only workers. There is no vendor commercial data,
 * no other doctor's examinations, and nothing outside their tenant.
 */
class DoctorPortalController extends Controller
{
    public function __construct(
        private TpvMedicalWorkflowService $tpv,
        private PurchaseMedicalWorkflowService $purchase,
        private MedicalCertificatePdfService $pdf,
    ) {}

    /* ── Identity ───────────────────────────────────────────────────────── */

    /** The signed-in doctor's own profile, and what they may do with it. */
    public function me(Request $request)
    {
        $user    = $request->user();
        $profile = $user->doctorProfile;

        return response()->json([
            'data' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'profile'    => $profile,
                // Without a licence a doctor can examine but not issue — the UI
                // uses this to explain why the save button is disabled.
                'is_signable' => (bool) $profile?->isSignable(),
                'modules'    => $this->modulesFor($profile),
            ],
        ]);
    }

    /** A doctor maintains their own practising details and signature. */
    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'license_no'     => 'nullable|string|max:60',
            'council'        => 'nullable|string|max:160',
            'qualification'  => 'nullable|string|max:160',
            'designation'    => 'nullable|string|max:120',
            'clinic_name'    => 'nullable|string|max:160',
            'clinic_address' => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:40',
            // Drawn once and reused on every certificate.
            'signature_data' => 'nullable|string',
            'stamp_data'     => 'nullable|string',
        ]);

        $user = $request->user();

        foreach (['signature_data' => 'signature_path', 'stamp_data' => 'stamp_path'] as $src => $column) {
            if (! empty($data[$src]) && str_contains($data[$src], 'base64,')) {
                $binary = base64_decode(explode('base64,', $data[$src])[1], true);
                if ($binary !== false) {
                    $path = 'medical/doctors/'.$column.'_'.$user->id.'_'.uniqid().'.png';
                    Storage::disk('public')->put($path, $binary);
                    $data[$column] = $path;
                }
            }
            unset($data[$src]);
        }

        $profile = MedicalDoctorProfile::updateOrCreate(
            ['user_id' => $user->id],
            [...$data, 'tenant_id' => $user->tenant_id],
        );

        return response()->json(['message' => 'Profile saved.', 'data' => $profile->fresh()]);
    }

    /** Dashboard counters, across both sides the doctor serves. */
    public function summary(Request $request)
    {
        $user = $request->user();
        $out  = ['modules' => [], 'total_examinations' => 0, 'pending_review' => 0, 'held' => 0, 'rejected' => 0];

        foreach ($this->modulesFor($user->doctorProfile) as $module) {
            $rows = $this->medicalQuery($module, $user->tenant_id)
                ->where('doctor_user_id', $user->id)
                ->get(['qc_status']);

            $counts = [
                'module'         => $module,
                'examinations'   => $rows->count(),
                'pending_review' => $rows->where('qc_status', MedicalQcStatus::PENDING)->count(),
                'held'           => $rows->where('qc_status', MedicalQcStatus::HOLD)->count(),
                'rejected'       => $rows->where('qc_status', MedicalQcStatus::REJECTED)->count(),
                'approved'       => $rows->where('qc_status', MedicalQcStatus::APPROVED)->count(),
            ];

            $out['modules'][] = $counts;
            $out['total_examinations'] += $counts['examinations'];
            $out['pending_review']     += $counts['pending_review'];
            $out['held']               += $counts['held'];
            $out['rejected']           += $counts['rejected'];
        }

        return response()->json(['data' => $out]);
    }

    /* ── Choosing a vendor and a worker ─────────────────────────────────── */

    /** The vendors on one side, for the doctor's first choice. */
    public function vendors(Request $request, string $module)
    {
        $this->assertModule($request, $module);
        $tenantId = $request->user()->tenant_id;
        $search   = trim((string) $request->query('q'));

        $rows = $module === 'tpv'
            ? Vendor::forTenant($tenantId)
                ->when($search, fn ($q) => $q->where('company_name', 'like', "%{$search}%"))
                ->orderBy('company_name')
                ->limit(500)->get(['id', 'company_name as name', 'vendor_code as code', 'status'])
            : PurchaseVendor::forTenant($tenantId)
                ->when($search, fn ($q) => $q->where('company_name', 'like', "%{$search}%"))
                ->orderBy('company_name')
                ->limit(500)->get(['id', 'company_name as name', 'vendor_code as code', 'status']);

        return response()->json(['data' => $rows]);
    }

    /**
     * The workers of one vendor, each carrying the state of their medical so
     * the doctor can see at a glance who is due, who is held and who is clear.
     */
    public function workers(Request $request, string $module)
    {
        $this->assertModule($request, $module);
        $tenantId = $request->user()->tenant_id;
        $vendorId = $request->query('vendor_id');
        $search   = trim((string) $request->query('q'));

        abort_unless($vendorId, 422, 'Select a vendor first.');

        if ($module === 'tpv') {
            $workers = TpvWorker::forTenant($tenantId)
                ->where('vendor_id', $vendorId)
                ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('worker_code', 'like', "%{$search}%")))
                ->with('medical')
                ->orderBy('name')->limit(500)->get();

            $data = $workers->map(fn ($w) => [
                'id' => $w->id, 'name' => $w->name, 'worker_code' => $w->worker_code,
                'designation' => $w->designation, 'photo' => $w->photo_path,
                'medical' => $w->medical, 'clearance' => $this->tpv->clearanceFor($w),
            ]);
        } else {
            $workers = PurchaseWorker::forTenant($tenantId)
                ->where('purchase_vendor_id', $vendorId)
                ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$search}%")->orWhere('worker_code', 'like', "%{$search}%")))
                ->with('latestMedical')
                ->orderBy('full_name')->limit(500)->get();

            $data = $workers->map(fn ($w) => [
                'id' => $w->id, 'name' => $w->full_name, 'worker_code' => $w->worker_code,
                'designation' => $w->designation, 'photo' => $w->photo_path,
                'medical' => $w->latestMedical, 'clearance' => $this->purchase->clearanceFor($w),
            ]);
        }

        return response()->json(['data' => $data]);
    }

    /** One worker: their details and their complete medical history. */
    public function worker(Request $request, string $module, int $worker)
    {
        $this->assertModule($request, $module);
        $subject = $this->findWorker($module, $worker, $request->user()->tenant_id);

        $history = $module === 'tpv'
            ? $this->tpv->history($subject)
            : $this->purchase->history($subject);

        return response()->json([
            'data' => [
                'worker'  => $this->describeWorker($module, $subject),
                'history' => $history,
                // The vocabulary the form needs, so the client never hard-codes it.
                'options' => [
                    'fitness_statuses' => TpvMedicalFitness::ALL,
                ],
            ],
        ]);
    }

    /* ── The examination ────────────────────────────────────────────────── */

    /**
     * Record an examination. The same endpoint serves a first examination and a
     * re-examination — `is_reexam` decides, and the service links the new record
     * to the one it supersedes so the history reads as a chain rather than a
     * pile.
     */
    public function examine(SaveExaminationRequest $request, string $module, int $worker)
    {
        $this->assertModule($request, $module);
        $user    = $request->user();
        $profile = $user->doctorProfile;

        // A certificate carries a licence number by law; refuse rather than
        // issue an unverifiable document.
        abort_unless((bool) $profile?->isSignable(), 422,
            'Add your licence number to your profile before recording an examination.');

        $subject = $this->findWorker($module, $worker, $user->tenant_id);
        $data    = $request->validated();
        $data['report_file'] = $request->file('report_file');

        $medical = $module === 'tpv'
            ? $this->tpv->record($subject, $data, $user, MedicalWorkflow::ORIGIN_DOCTOR_PORTAL)
            : $this->purchase->record($subject, $data, $user, MedicalWorkflow::ORIGIN_DOCTOR_PORTAL);

        // Generate the prescription immediately — the doctor's next action is
        // almost always to hand it over or print it.
        $this->pdf->store($medical, $this->describeWorker($module, $subject));

        return response()->json([
            'message' => $medical->is_reexam ? 'Re-examination recorded.' : 'Examination recorded.',
            'data'    => $medical->fresh(),
        ], 201);
    }

    /** The doctor's own examinations on one side, newest first. */
    public function examinations(Request $request, string $module)
    {
        $this->assertModule($request, $module);
        $user = $request->user();

        $rows = $this->medicalQuery($module, $user->tenant_id)
            ->where('doctor_user_id', $user->id)
            ->when($request->query('qc_status'), fn ($q, $s) => $q->where('qc_status', $s))
            ->with($module === 'tpv' ? ['worker:id,name,worker_code'] : ['worker:id,full_name,worker_code'])
            ->orderByDesc('exam_date')->orderByDesc('id')
            ->limit(500)->get();

        return response()->json(['data' => $rows, 'statuses' => MedicalQcStatus::ALL]);
    }

    /** One examination, with its back-and-forth timeline. */
    public function examination(Request $request, string $module, int $medical)
    {
        $this->assertModule($request, $module);
        $record = $this->findMedical($module, $medical, $request->user()->tenant_id);

        abort_unless((int) $record->doctor_user_id === (int) $request->user()->id, 404);

        return response()->json([
            'data' => [
                'medical'  => $record,
                'timeline' => $module === 'tpv' ? $this->tpv->timeline($record) : $this->purchase->timeline($record),
            ],
        ]);
    }

    /** The generated prescription, streamed inline. */
    public function certificate(Request $request, string $module, int $medical)
    {
        $this->assertModule($request, $module);
        $record  = $this->findMedical($module, $medical, $request->user()->tenant_id);
        $subject = $this->describeWorker($module, $record->worker);

        return $this->pdf->render($record, $subject)
            ->stream(($record->certificate_no ?: 'medical-certificate').'.pdf');
    }

    /* ── Module plumbing ────────────────────────────────────────────────── */

    /** Which sides this doctor serves — both, unless their profile narrows it. */
    private function modulesFor(?MedicalDoctorProfile $profile): array
    {
        return array_values(array_filter(
            ['tpv', 'purchase'],
            fn ($m) => $profile === null || $profile->servesModule($m),
        ));
    }

    /** 404 rather than 403 for a side this doctor does not serve — it is not theirs to know about. */
    private function assertModule(Request $request, string $module): void
    {
        abort_unless(in_array($module, ['tpv', 'purchase'], true), 404);
        abort_unless(
            in_array($module, $this->modulesFor($request->user()->doctorProfile), true),
            404,
        );
    }

    private function medicalQuery(string $module, int $tenantId)
    {
        return $module === 'tpv'
            ? TpvWorkerMedical::forTenant($tenantId)
            : PurchaseWorkerMedical::forTenant($tenantId);
    }

    /** Resolve a worker inside the caller's tenant; anything else reads as absent. */
    private function findWorker(string $module, int $id, int $tenantId)
    {
        $worker = $module === 'tpv'
            ? TpvWorker::forTenant($tenantId)->find($id)
            : PurchaseWorker::forTenant($tenantId)->find($id);

        abort_unless($worker, 404, 'Worker not found.');

        return $worker;
    }

    private function findMedical(string $module, int $id, int $tenantId)
    {
        $record = $this->medicalQuery($module, $tenantId)->with('worker')->find($id);

        abort_unless($record, 404, 'Examination not found.');

        return $record;
    }

    /**
     * The worker facts a certificate prints. The two sides name their columns
     * differently, and this is the one place that difference is spelled out.
     *
     * @return array<string,string|null>
     */
    private function describeWorker(string $module, $worker): array
    {
        if (! $worker) {
            return [];
        }

        return $module === 'tpv'
            ? [
                'worker_name' => $worker->name,
                'worker_code' => $worker->worker_code,
                'vendor_name' => $worker->vendor?->company_name,
                'designation' => $worker->designation,
                'dob'         => optional($worker->dob)->format('d M Y'),
                'gender'      => $worker->gender,
            ]
            : [
                'worker_name' => $worker->full_name,
                'worker_code' => $worker->worker_code,
                'vendor_name' => $worker->vendor?->company_name,
                'designation' => $worker->designation,
                'dob'         => optional($worker->dob)->format('d M Y'),
                'gender'      => $worker->gender,
            ];
    }
}
