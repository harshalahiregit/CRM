<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Models\Purchase\PurchaseWorkerTraining;
use App\Services\Purchase\PurchasePpeService;
use App\Services\Purchase\PurchaseWorkforceService;
use Illuminate\Http\Request;

/**
 * Admin/staff view of the Purchase workforce.
 *
 * The vendor supplies the evidence — profile, medical, training, induction, PPE —
 * and this is where the site reviews it and decides who may walk in. Scoped by
 * TENANT rather than by vendor, which is the difference between this and the
 * portal controller: an admin legitimately sees every vendor's workers.
 *
 * Badge activation is deliberately here and nowhere else.
 */
class PurchaseWorkforceAdminController extends Controller
{
    public function __construct(private PurchaseWorkforceService $service)
    {
    }

    /** Every Purchase worker in the tenant, optionally filtered to one vendor. */
    public function index(Request $request)
    {
        // latestMedical / latestInduction ride along so the register can show each
        // worker's step-2 and step-3 evidence without a request per row. Both are
        // hasOne-of-many subqueries, so this stays three queries no matter how
        // large the workforce is.
        $q = PurchaseWorker::forTenant($request->user()->tenant_id)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'latestMedical', 'latestInduction']);

        if ($request->filled('vendor_id')) {
            $q->where('purchase_vendor_id', (int) $request->input('vendor_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json(['data' => $q->latest()->get()]);
    }

    /* ── Vendor detail: Medical & Training tabs ────────────────────────
     *
     * Purchase keeps these NORMALISED — purchase_worker_medicals and
     * purchase_worker_trainings are one-to-many, so a worker legitimately has a
     * history of each. These list the records themselves rather than projecting
     * one row per worker (which is how TPV models it, on a single wide
     * tpv_workers row) — showing only the latest would misrepresent the history.
     *
     * whereHas('worker') is load-bearing: PurchaseWorkforceService::delete only
     * soft-deletes the worker and these child tables carry no FK, so orphaned
     * medicals would otherwise keep appearing on the tab.
     */

    public function medicals(Request $request)
    {
        return response()->json(['data' => $this->vendorScoped($request, PurchaseWorkerMedical::query())
            ->orderByDesc('exam_date')->orderByDesc('id')->get()]);
    }

    public function trainings(Request $request)
    {
        return response()->json(['data' => $this->vendorScoped($request, PurchaseWorkerTraining::query())
            ->orderByDesc('id')->get()]);
    }

    /**
     * Tenant + vendor narrowing shared by both.
     *
     * The vendor filter is STRICT — a missing vendor_id returns nothing rather
     * than the tenant's whole workforce, because these endpoints exist only to
     * serve one vendor's tab.
     */
    private function vendorScoped(Request $request, $query)
    {
        $vendorId = (int) $request->query('vendor_id');
        abort_if($vendorId <= 0, 422, 'A vendor is required.');

        return $query
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->where('purchase_vendor_id', $vendorId)
            ->whereHas('worker')
            ->with('worker:id,full_name,worker_code,designation');
    }

    public function show(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json([
            'worker'    => $worker->load(['vendor:id,company_name', 'documents', 'medicals', 'trainings', 'inductions']),
            'readiness' => $this->service->readiness($worker),
            'badge'     => [
                'badge_number'      => $worker->badge_number,
                'badge_issued_at'   => optional($worker->badge_issued_at)->toIso8601String(),
                'badge_valid_until' => optional($worker->badge_valid_until)->toDateString(),
                'activated'         => (bool) $worker->badge_number,
            ],
        ]);
    }

    /** A worker's PPE, from the central ledger. */
    public function ppe(Request $request, PurchaseWorker $worker, PurchasePpeService $ppe)
    {
        $this->assertTenant($request, $worker);

        return response()->json([
            'issues'     => $ppe->forWorker($worker)->values(),
            'compliance' => $ppe->complianceFor($worker),
        ]);
    }

    /**
     * Step 5 — activate the worker and issue its entry badge. ADMIN ONLY.
     *
     * The route carries role:admin, so staff and vendors never reach this. The
     * service re-checks readiness, so the badge cannot outrun the evidence.
     */
    public function activate(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate(['valid_until' => 'nullable|date']);

        return response()->json($this->service->activateBadge($worker, $request->user(), $data));
    }

    /** Suspend a worker — withholds site access until reinstated. */
    public function suspend(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return response()->json($this->service->suspend($worker, $request->user(), $data['reason'] ?? null));
    }

    /** Lift a suspension and return the worker to Active. */
    public function reinstate(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->reinstate($worker, $request->user()));
    }

    /** Terminate a worker — permanent; nulls the QR token so the badge cannot scan back in. */
    public function terminate(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return response()->json($this->service->terminate($worker, $request->user(), $data['reason'] ?? null));
    }

    /**
     * Admin-side PPE return/write-off, for kit handed back at the gate rather
     * than through the vendor's portal.
     */
    public function returnPpe(Request $request, PurchaseWorkerPpeIssue $issue, PurchasePpeService $ppe)
    {
        abort_unless(
            (int) $issue->tenant_id === (int) $request->user()->tenant_id,
            404,
            'PPE issue not found'
        );

        $data = $request->validate([
            'condition' => 'nullable|in:returned,lost,damaged',
            'qty'       => 'nullable|numeric|min:0.001',
            'notes'     => 'nullable|string|max:2000',
        ]);

        return response()->json($ppe->returnIssue($issue, $data, $request->user()));
    }

    /**
     * The PPE catalogue — what kit exists to issue.
     *
     * PurchasePpeService::catalogue() has always existed and was reachable only
     * through the vendor portal, so the admin app had no way to see the kit it
     * is meant to be issuing. Read-only; issuing still goes through the worker.
     */
    public function ppeCatalogue(Request $request, PurchasePpeService $ppe)
    {
        return response()->json(['data' => $ppe->catalogue((int) $request->user()->tenant_id)->values()]);
    }

    /**
     * Issue PPE to a worker from the admin side.
     *
     * The vendor portal could issue kit and staff could not, which left the
     * store-keeper at the gate unable to record what they had just handed over.
     * Same service call the portal makes, so the ledger stays single-sourced.
     */
    public function issuePpe(Request $request, PurchaseWorker $worker, PurchasePpeService $ppe)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate([
            'product_id' => 'required|integer',
            'qty'        => 'nullable|numeric|min:0.001',
            'size'       => 'nullable|string|max:40',
            'issued_at'  => 'nullable|date',
            'notes'      => 'nullable|string|max:2000',
        ]);

        return response()->json($ppe->issue($worker, $data, $request->user()), 201);
    }

    /** The gate decision for a scanned worker. */
    public function gate(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->gateDecision($worker));
    }

    /* ── Admin-side worker management ──────────────────────────────────────
     *
     * The vendor portal has always been able to register and edit its own
     * workers (PurchasePortalWorkforceController); staff could only read them.
     * That left no way to add a worker from the admin side at all, which is the
     * TPV flow staff already know. These mirror TpvWorkerController one for one,
     * on Purchase's own tables, and reuse the same PurchaseWorkforceService the
     * portal calls — so both sides can never drift apart.
     */

    /** Tenant-wide workforce counters. Mirrors TpvWorkerService::stats(). */
    public function stats(Request $request)
    {
        $base = fn () => PurchaseWorker::forTenant($request->user()->tenant_id);

        return response()->json([
            'total'      => $base()->count(),
            'draft'      => $base()->where('status', 'Pending')->count(),
            'active'     => $base()->where('status', 'Active')->count(),
            'suspended'  => $base()->where('status', 'Suspended')->count(),
            'terminated' => $base()->where('status', 'Terminated')->count(),
            // Badges lapsing within 30 days — the renewal queue.
            'expiring'   => $base()->where('status', 'Active')
                                   ->whereNotNull('badge_valid_until')
                                   ->whereBetween('badge_valid_until', [now()->toDateString(), now()->addDays(30)->toDateString()])
                                   ->count(),
        ]);
    }

    /** Register a worker against a vendor. Step 1 of the wizard. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id'       => 'required|integer',
            'full_name'       => 'required|string|max:150',
            'gender'          => 'nullable|string|max:20',
            'dob'             => 'nullable|date',
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'designation'     => 'nullable|string|max:120',
            'id_proof_type'   => 'nullable|string|max:60',
            'id_proof_number' => 'nullable|string|max:80',
            'address'         => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:120',
            'state'           => 'nullable|string|max:120',
            'pincode'         => 'nullable|string|max:20',
            'status'          => 'nullable|in:Active,Inactive,Pending',
            'notes'           => 'nullable|string|max:2000',
            // TPV-parity identity + employment depth.
            'blood_group'       => 'nullable|string|max:10',
            'skill_category'    => 'nullable|string|max:120',
            'trade'             => 'nullable|string|max:120',
            'age_reason'        => 'nullable|string|max:255',
            'emergency_contact' => 'nullable|string|max:150',
            'emergency_phone'   => 'nullable|string|max:30',
            'bocw_number'       => 'nullable|string|max:60',
            'experience_years'  => 'nullable|numeric|between:0,60',
            'joining_date'      => 'nullable|date',
            'exit_date'         => 'nullable|date',
            'project'           => 'nullable|string|max:150',
            'site'              => 'nullable|string|max:150',
            'department'        => 'nullable|string|max:120',
        ]);

        $vendor = $this->vendorForTenant($request, (int) $data['vendor_id']);
        unset($data['vendor_id']);

        return response()->json($this->service->create($vendor, $data), 201);
    }

    /** Edit a worker's profile. */
    public function update(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate([
            'full_name'       => 'sometimes|required|string|max:150',
            'gender'          => 'nullable|string|max:20',
            'dob'             => 'nullable|date',
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'designation'     => 'nullable|string|max:120',
            'id_proof_type'   => 'nullable|string|max:60',
            'id_proof_number' => 'nullable|string|max:80',
            'address'         => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:120',
            'state'           => 'nullable|string|max:120',
            'pincode'         => 'nullable|string|max:20',
            'status'          => 'nullable|in:Active,Inactive,Pending',
            'notes'           => 'nullable|string|max:2000',
            // TPV-parity identity + employment depth.
            'blood_group'       => 'nullable|string|max:10',
            'skill_category'    => 'nullable|string|max:120',
            'trade'             => 'nullable|string|max:120',
            'age_reason'        => 'nullable|string|max:255',
            'emergency_contact' => 'nullable|string|max:150',
            'emergency_phone'   => 'nullable|string|max:30',
            'bocw_number'       => 'nullable|string|max:60',
            'experience_years'  => 'nullable|numeric|between:0,60',
            'joining_date'      => 'nullable|date',
            'exit_date'         => 'nullable|date',
            'project'           => 'nullable|string|max:150',
            'site'              => 'nullable|string|max:150',
            'department'        => 'nullable|string|max:120',
        ]);

        return response()->json($this->service->update($worker, $data));
    }

    /** Remove a worker. Soft delete, exactly as the portal does it. */
    public function destroy(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $this->service->delete($worker);

        return response()->json(['message' => 'Worker removed']);
    }

    /**
     * Step 2 — medical fitness.
     *
     * `valid_until` and `provider` are accepted as aliases and mapped onto the
     * columns the service actually writes (expiry_date / examiner_name). The UI
     * speaks TPV's names for these two, and validating them under those names
     * while the service read different ones meant the expiry and the examiner
     * were accepted, reported saved, and silently discarded.
     */
    public function saveMedical(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate([
            'exam_date'      => 'nullable|date',
            'expiry_date'    => 'nullable|date',
            'valid_until'    => 'nullable|date',   // alias → expiry_date
            'fitness_status' => 'nullable|string|max:40',
            'examiner_name'  => 'nullable|string|max:150',
            'provider'       => 'nullable|string|max:150', // alias → examiner_name
            'blood_group'    => 'nullable|string|max:10',
            'restrictions'   => 'nullable|string|max:2000',
            'remarks'        => 'nullable|string|max:5000',
            // Examination depth — vitals, the scored screening, and the §16
            // capture. Recorded as data rather than folded into remarks, so the
            // fitness bands are computed instead of re-read out of a sentence.
            'exam_type'           => 'nullable|string|max:60',
            'clinic_name'         => 'nullable|string|max:150',
            'height_cm'           => 'nullable|numeric|between:100,250',
            'weight_kg'           => 'nullable|numeric|between:20,300',
            'bp_systolic'         => 'nullable|integer|between:50,300',
            'bp_diastolic'        => 'nullable|integer|between:30,200',
            'vision'              => 'nullable|string|max:60',
            'screening_responses' => 'nullable|array',
            'screening_score'     => 'nullable|integer|min:0',
            'screening_band'      => 'nullable|string|max:20',
            'signature_path'      => 'nullable|string|max:255',
            'capture_photo_path'  => 'nullable|string|max:255',
            'geo_location'        => 'nullable|string|max:120',
        ]);

        $data['expiry_date']   ??= $data['valid_until'] ?? null;
        $data['examiner_name'] ??= $data['provider'] ?? null;
        // Who recorded it and from where are taken from the request, never from
        // the payload — a client must not be able to attribute an examination to
        // someone else or claim a different origin.
        $data['recorded_by'] = $request->user()->id;
        $data['system_ip']   = $request->ip();

        return response()->json($this->service->saveMedical($worker, $data));
    }

    /**
     * Step 3a — training. Step 3 only clears when BOTH a training and an
     * induction record exist (see PurchaseWorkforceService::stepThreeCleared),
     * so without this endpoint an admin-registered worker could never leave
     * step 3 and could never be badged.
     */
    public function saveTraining(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate([
            'title'         => 'nullable|string|max:150',
            'training_type' => 'nullable|string|max:60',
            'provider'      => 'nullable|string|max:150',
            'training_date' => 'nullable|date',
            'expiry_date'   => 'nullable|date',
            'valid_until'   => 'nullable|date',
            'status'        => 'nullable|string|max:40',
            'score'         => 'nullable|numeric',
            'remarks'       => 'nullable|string|max:5000',
        ]);

        return response()->json($this->service->saveTraining($worker, $data));
    }

    /** Step 3b — site induction. */
    public function saveInduction(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate([
            'induction_date' => 'nullable|date',
            'status'         => 'nullable|string|max:40',
            'conducted_by'   => 'nullable|string|max:150',
            'remarks'        => 'nullable|string|max:5000',
            // Session depth + attendance proof.
            'trainer_name'     => 'nullable|string|max:150',
            'training_date'    => 'nullable|date',
            'valid_until'      => 'nullable|date',
            'duration_minutes' => 'nullable|integer|between:1,1440',
            'topics'           => 'nullable|array',
            'score'            => 'nullable|integer|between:0,100',
            'passed'           => 'nullable|boolean',
            'photo_path'       => 'nullable|string|max:255',
            'signature_path'   => 'nullable|string|max:255',
            'thumbprint_path'  => 'nullable|string|max:255',
        ]);

        $data['recorded_by'] = $request->user()->id;

        return response()->json($this->service->saveInduction($worker, $data));
    }

    /** The worker's badge — what the gate scans. */
    public function badge(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json([
            'worker'            => $worker->only(['id', 'full_name', 'worker_code', 'designation', 'status']),
            'vendor'            => $worker->vendor?->only(['id', 'company_name', 'purchase_vendor_code']),
            'badge_number'      => $worker->badge_number,
            'badge_issued_at'   => optional($worker->badge_issued_at)->toIso8601String(),
            'badge_valid_until' => optional($worker->badge_valid_until)->toDateString(),
            'activated'         => (bool) $worker->badge_number,
        ]);
    }

    /** A vendor in the caller's tenant, or 404. */
    private function vendorForTenant(Request $request, int $vendorId): \App\Models\Purchase\PurchaseVendor
    {
        $vendor = \App\Models\Purchase\PurchaseVendor::where('id', $vendorId)
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->first();

        abort_if($vendor === null, 404, 'Vendor not found');

        return $vendor;
    }

    /** 404, not 403 — the same existence-hiding the Purchase portal uses. */
    private function assertTenant(Request $request, PurchaseWorker $worker): void
    {
        abort_unless(
            (int) $worker->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Worker not found'
        );
    }
}
