<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tpv\IssueWorkerPpeRequest;
use App\Http\Requests\Tpv\SaveWorkerInductionRequest;
use App\Http\Requests\Tpv\SaveWorkerMedicalRequest;
use App\Http\Requests\Tpv\StoreTpvWorkerRequest;
use App\Http\Requests\Tpv\UpdateTpvWorkerRequest;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Services\Tpv\TpvWorkerService;
use Illuminate\Http\Request;

class TpvWorkerController extends Controller
{
    public function __construct(private TpvWorkerService $workerService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->workerService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'vendor_id', 'skill_category', 'search'])
            )
        );
    }

    public function store(StoreTpvWorkerRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        // Smart vendor resolution: prefer portalVendor / user vendor email / user vendor_id / first vendor
        $vendorId = $request->attributes->get('portalVendor')?->id
            ?? $user->vendor_id
            ?? \App\Models\Vendor\Vendor::where('tenant_id', $user->tenant_id)->where('email', $user->email)->first()?->id
            ?? $request->input('vendor_id')
            ?? \App\Models\Vendor\Vendor::where('tenant_id', $user->tenant_id)->first()?->id;

        $data['vendor_id'] = (int) $vendorId;

        $worker = $this->workerService->create($data, $request->user());

        return response()->json($worker, 201);
    }

    public function show(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $worker->load(['vendor', 'medical.recorder:id,name', 'induction.recorder:id,name', 'ppeIssues.issuer:id,name', 'creator:id,name', 'auditLogs']);

        return response()->json([
            'worker'   => $worker,
            'progress' => $this->workerService->stepStatus($worker),
        ]);
    }

    /** Per-step completion + the live gate blockers. */
    public function progress(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->stepStatus($worker));
    }

    public function update(Request $request, TpvWorker $worker, UpdateTpvWorkerRequest $updateRequest)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->update($worker, $updateRequest->validated(), $request->user()));
    }

    public function saveMedical(SaveWorkerMedicalRequest $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->saveMedical($worker, $request->validated(), $request->user()));
    }

    public function saveInduction(SaveWorkerInductionRequest $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->saveInduction($worker, $request->validated(), $request->user()));
    }

    public function issuePpe(IssueWorkerPpeRequest $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->issuePpe($worker, $request->validated(), $request->user()));
    }

    public function removePpe(Request $request, TpvWorker $worker, TpvWorkerPpeIssue $ppeIssue)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->removePpe($worker, $ppeIssue, $request->user()));
    }

    /** Step 5 — issue the entry badge (admin). Returns the QR token once. */
    public function activate(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate(['valid_until' => 'nullable|date|after:today']);

        $worker = $this->workerService->activate($worker, $request->user(), $data['valid_until'] ?? null);

        return response()->json([
            'worker'    => $worker,
            // The token is hidden on the model — surfaced here so the badge can be
            // rendered/printed at issue time.
            'qr_token'  => $worker->qr_token,
        ]);
    }

    /**
     * Reveal the badge credential for (re)printing — admin only and audited,
     * since the QR token is a bearer credential for the site gate.
     */
    public function badge(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->badge($worker, $request->user()));
    }

    public function suspend(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->workerService->suspend($worker, $request->user(), $data['remarks'] ?? null));
    }

    public function reinstate(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->workerService->reinstate($worker, $request->user()));
    }

    public function terminate(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate(['remarks' => 'required|string']);

        return response()->json($this->workerService->terminate($worker, $request->user(), $data['remarks']));
    }

    public function toggleStatus(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $status = (bool) $request->input('is_active', true);
        $worker->update(['is_active' => $status]);
        return response()->json(['status' => 'success', 'message' => 'Worker status updated.', 'is_active' => $status]);
    }

    public function uploadWorkers(Request $request)
    {
        $request->validate([
            'worker_file' => 'required|file|mimes:csv,xls,xlsx,txt,zip|max:20480',
            'vendor_id'   => 'nullable|integer',
        ]);

        $user = $request->user();

        // Smart vendor resolution: prefer portalVendor / user vendor email / user vendor_id / first vendor
        $vendorId = $request->attributes->get('portalVendor')?->id
            ?? $user->vendor_id
            ?? \App\Models\Vendor\Vendor::where('tenant_id', $user->tenant_id)->where('email', $user->email)->first()?->id
            ?? $request->input('vendor_id')
            ?? \App\Models\Vendor\Vendor::where('tenant_id', $user->tenant_id)->first()?->id;

        $res = $this->workerService->bulkUpload(
            $request->file('worker_file'),
            $vendorId,
            $request->user()->tenant_id,
            $request->user()
        );

        return response()->json($res);
    }

    public function markMedical(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $data = $request->all();

        if (($data['medical_type'] ?? '') === 'skip') {
            $worker->update(['medical_status' => 2, 'medical_type' => 'skip']);
            return response()->json(['status' => 'success', 'message' => 'Medical skipped.']);
        }

        $dob = $data['dob'] ?? $worker->dob?->toDateString();
        $ageData = TpvWorkerService::calcAge($dob);

        $update = [
            'medical_status'       => 1,
            'medical_type'         => $data['medical_type'] ?? 'internal',
            'doctor_name'          => $data['doctor_name'] ?? null,
            'organization_name'    => $data['organization_name'] ?? null,
            'doctor_registration'  => $data['doctor_registration'] ?? null,
            'doctor_designation'   => $data['doctor_designation'] ?? null,
            'dob'                  => $dob,
            'age'                  => $ageData['age'],
            'eyesight'             => $data['eyesight'] ?? null,
            'height'               => $data['height'] ?? null,
            'weight'               => $data['weight'] ?? null,
            'blood_pressure'       => $data['blood_pressure'] ?? null,
            'height_phobia'        => $data['height_phobia'] ?? 'no',
            'heart_disease'        => $data['heart_disease'] ?? 'no',
            'habits'               => $data['habits'] ?? null,
            'handicapped'          => $data['handicapped'] ?? 'no',
            'system_ip'            => $request->ip(),
            'geo_location'         => $data['geo_location'] ?? null,
            'mh_version'           => (int) ($data['mh_version'] ?? 1),
            'mh_q1'                => isset($data['mh_q1']) ? (int)$data['mh_q1'] : null,
            'mh_q2'                => isset($data['mh_q2']) ? (int)$data['mh_q2'] : null,
            'mh_q3'                => isset($data['mh_q3']) ? (int)$data['mh_q3'] : null,
            'mh_q4'                => isset($data['mh_q4']) ? (int)$data['mh_q4'] : null,
            'mh_q5'                => isset($data['mh_q5']) ? (int)$data['mh_q5'] : null,
            'mh_q6'                => isset($data['mh_q6']) ? (int)$data['mh_q6'] : null,
            'mh_score'             => $data['mh_score'] ?? null,
            'mh_risk'              => $data['mh_risk'] ?? null,
            'doctor_comments'      => $data['doctor_comments'] ?? null,
            'physical_score'       => $data['physical_score'] ?? null,
            'medical_result'       => $data['medical_result'] ?? 'fit',
            'external_doctor_name' => $data['external_doctor_name'] ?? null,
            'external_doctor_regid'=> $data['external_doctor_regid'] ?? null,
        ];

        if ($request->hasFile('signature_file')) {
            $update['signature_file'] = $request->file('signature_file')->store('workers/signatures', 'public');
        } elseif (!empty($data['signature_data']) && str_contains($data['signature_data'], 'base64,')) {
            $imgData = explode('base64,', $data['signature_data'])[1];
            $filename = 'workers/signatures/sig_'.uniqid().'.png';
            \Storage::disk('public')->put($filename, base64_decode($imgData));
            $update['signature_file'] = $filename;
        }

        if (!empty($data['stamp_data']) && str_contains($data['stamp_data'], 'base64,')) {
            $imgData = explode('base64,', $data['stamp_data'])[1];
            $filename = 'workers/stamps/stamp_'.uniqid().'.png';
            \Storage::disk('public')->put($filename, base64_decode($imgData));
            $update['stamp_file'] = $filename;
        }

        if ($request->hasFile('external_pdf')) {
            $update['external_pdf'] = $request->file('external_pdf')->store('workers/external_pdf', 'public');
        }

        $worker->update($update);
        return response()->json(['status' => 'success', 'message' => 'Medical details saved successfully.']);
    }

    public function markInduction(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $data = $request->all();

        if (($data['induction_type'] ?? '') === 'skip') {
            $worker->update(['induction_status' => 2, 'induction_type' => 'skip']);
            return response()->json(['status' => 'success', 'message' => 'Induction skipped.']);
        }

        $start = $data['start_time'] ?? $data['induction_start'] ?? null;
        $end   = $data['end_time'] ?? $data['induction_end'] ?? null;
        $duration = $data['duration_minutes'] ?? null;
        if (!$duration && $start && $end) {
            try {
                $duration = (new \DateTime($start))->diff(new \DateTime($end))->i;
            } catch (\Exception $e) {}
        }

        $update = [
            'induction_status'   => 1,
            'induction_type'     => $data['induction_type'] ?? 'General Safety',
            'trainer'            => $data['trainer'] ?? $data['trainer_name'] ?? 'Safety Officer – Rahul Sharma',
            'induction_location' => $data['location'] ?? $data['induction_location'] ?? 'Site Office',
            'induction_start'    => $start,
            'induction_end'      => $end,
            'induction_duration' => $duration,
            'induction_device'   => substr($request->userAgent() ?? '', 0, 255),
        ];

        if (!empty($data['photo_data']) && str_contains($data['photo_data'], 'base64,')) {
            $imgData = explode('base64,', $data['photo_data'])[1];
            $filename = 'workers/induction_photos/photo_'.uniqid().'.png';
            \Storage::disk('public')->put($filename, base64_decode($imgData));
            $update['induction_photo'] = $filename;
        } elseif ($request->hasFile('induction_photo')) {
            $update['induction_photo'] = $request->file('induction_photo')->store('workers/induction_photos', 'public');
        }

        $proofData = $data['signature_data'] ?? $data['thumb_data'] ?? null;
        if (!empty($proofData) && str_contains($proofData, 'base64,')) {
            $imgData = explode('base64,', $proofData)[1];
            $filename = 'workers/induction_proof/proof_'.uniqid().'.png';
            \Storage::disk('public')->put($filename, base64_decode($imgData));
            $update['induction_proof_file'] = $filename;
        } elseif ($request->hasFile('induction_proof_file')) {
            $update['induction_proof_file'] = $request->file('induction_proof_file')->store('workers/induction_proof', 'public');
        }

        $worker->update($update);
        return response()->json(['status' => 'success', 'message' => 'Induction details saved successfully.']);
    }

    public function getPpeInventory(Request $request)
    {
        $defaultItems = [
            ['commodity_id' => 1, 'name' => 'Safety Helmet', 'available_qty' => 150, 'min_qty' => 10, 'total_issued' => 45],
            ['commodity_id' => 2, 'name' => 'Safety Shoes', 'available_qty' => 200, 'min_qty' => 15, 'total_issued' => 60],
            ['commodity_id' => 3, 'name' => 'High-Visibility Reflective Vest', 'available_qty' => 300, 'min_qty' => 20, 'total_issued' => 90],
            ['commodity_id' => 4, 'name' => 'Full Body Safety Harness', 'available_qty' => 85, 'min_qty' => 5, 'total_issued' => 12],
            ['commodity_id' => 5, 'name' => 'Cut-Resistant Gloves', 'available_qty' => 400, 'min_qty' => 25, 'total_issued' => 110],
            ['commodity_id' => 6, 'name' => 'Safety Goggles', 'available_qty' => 250, 'min_qty' => 15, 'total_issued' => 30],
            ['commodity_id' => 7, 'name' => 'Ear Plugs / Defenders', 'available_qty' => 500, 'min_qty' => 30, 'total_issued' => 150],
        ];

        $stock = \Cache::get('tpv_ppe_inventory', $defaultItems);
        return response()->json(['items' => array_values($stock)]);
    }

    public function updatePpeStock(Request $request)
    {
        $data = $request->validate([
            'commodity_id'  => 'required|integer',
            'available_qty' => 'required|integer|min:0',
        ]);

        $defaultItems = [
            1 => ['commodity_id' => 1, 'name' => 'Safety Helmet', 'available_qty' => 150, 'min_qty' => 10, 'total_issued' => 45],
            2 => ['commodity_id' => 2, 'name' => 'Safety Shoes', 'available_qty' => 200, 'min_qty' => 15, 'total_issued' => 60],
            3 => ['commodity_id' => 3, 'name' => 'High-Visibility Reflective Vest', 'available_qty' => 300, 'min_qty' => 20, 'total_issued' => 90],
            4 => ['commodity_id' => 4, 'name' => 'Full Body Safety Harness', 'available_qty' => 85, 'min_qty' => 5, 'total_issued' => 12],
            5 => ['commodity_id' => 5, 'name' => 'Cut-Resistant Gloves', 'available_qty' => 400, 'min_qty' => 25, 'total_issued' => 110],
            6 => ['commodity_id' => 6, 'name' => 'Safety Goggles', 'available_qty' => 250, 'min_qty' => 15, 'total_issued' => 30],
            7 => ['commodity_id' => 7, 'name' => 'Ear Plugs / Defenders', 'available_qty' => 500, 'min_qty' => 30, 'total_issued' => 150],
        ];

        $stock = \Cache::get('tpv_ppe_inventory_map', $defaultItems);
        $cid = (int) $data['commodity_id'];
        if (isset($stock[$cid])) {
            $stock[$cid]['available_qty'] = (int) $data['available_qty'];
        }
        \Cache::put('tpv_ppe_inventory_map', $stock, now()->addDays(30));
        \Cache::put('tpv_ppe_inventory', array_values($stock), now()->addDays(30));

        return response()->json(['status' => 'success', 'message' => 'Stock updated', 'items' => array_values($stock)]);
    }

    public function markPpe(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $data = $request->all();

        if (($data['ppe_status'] ?? '') === 'skip') {
            $worker->update(['ppe_status' => 2, 'ppe_items' => null]);
            return response()->json(['status' => 'success', 'message' => 'PPE skipped.']);
        }

        $items = $data['ppe_items'] ?? [];
        $itemsStr = is_array($items) ? implode(',', $items) : (string) $items;
        $quantities = $data['quantities'] ?? [];

        // Decrement stock inventory in cache/database
        $defaultItems = [
            1 => ['commodity_id' => 1, 'name' => 'Safety Helmet', 'available_qty' => 150, 'min_qty' => 10, 'total_issued' => 45],
            2 => ['commodity_id' => 2, 'name' => 'Safety Shoes', 'available_qty' => 200, 'min_qty' => 15, 'total_issued' => 60],
            3 => ['commodity_id' => 3, 'name' => 'High-Visibility Reflective Vest', 'available_qty' => 300, 'min_qty' => 20, 'total_issued' => 90],
            4 => ['commodity_id' => 4, 'name' => 'Full Body Safety Harness', 'available_qty' => 85, 'min_qty' => 5, 'total_issued' => 12],
            5 => ['commodity_id' => 5, 'name' => 'Cut-Resistant Gloves', 'available_qty' => 400, 'min_qty' => 25, 'total_issued' => 110],
            6 => ['commodity_id' => 6, 'name' => 'Safety Goggles', 'available_qty' => 250, 'min_qty' => 15, 'total_issued' => 30],
            7 => ['commodity_id' => 7, 'name' => 'Ear Plugs / Defenders', 'available_qty' => 500, 'min_qty' => 30, 'total_issued' => 150],
        ];

        $stock = \Cache::get('tpv_ppe_inventory_map', $defaultItems);

        foreach ($quantities as $cid => $qty) {
            $cidInt = (int) $cid;
            $qtyInt = (int) $qty;
            if (isset($stock[$cidInt])) {
                $stock[$cidInt]['available_qty'] = max(0, $stock[$cidInt]['available_qty'] - $qtyInt);
                $stock[$cidInt]['total_issued'] += $qtyInt;
            }
        }

        \Cache::put('tpv_ppe_inventory_map', $stock, now()->addDays(30));
        \Cache::put('tpv_ppe_inventory', array_values($stock), now()->addDays(30));

        $worker->update([
            'ppe_status'    => 1,
            'ppe_items'     => $itemsStr,
            'ppe_issued_to' => $data['ppe_issued_to'] ?? 'vendor',
            'ppe_remarks'   => $data['ppe_remarks'] ?? null,
            'ppe_issued_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'PPE kit issued successfully and stock updated.']);
    }

    public function markCardStatus(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $cardStatus = (int) $request->input('card_status', 1);

        $worker->update([
            'card_status'    => $cardStatus,
            'card_issued_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Entry card status updated successfully.']);
    }

    public function markPunch(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $request->validate([
            'punch_count'  => 'required|integer|in:1,2,3',
            'punch_reason' => 'required|string',
        ]);

        $updatedWorker = $this->workerService->applyPunch(
            $worker,
            (int) $request->input('punch_count'),
            (string) $request->input('punch_reason'),
            $request->user()
        );

        return response()->json([
            'status'      => 'success',
            'message'     => 'Punch '.$updatedWorker->punch_count.' applied successfully.',
            'worker'      => $updatedWorker,
        ]);
    }

    public function decideWorker(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);
        $request->validate([
            'status'  => 'required|string|in:Approved,Rejected,On_Hold,Resubmit_Required',
            'remarks' => 'nullable|string',
        ]);

        $status = $request->input('status');
        $remarks = $request->input('remarks');

        $worker->update([
            'approval_status'  => $status,
            'approval_remarks' => $remarks,
            'approved_at'      => $status === 'Approved' ? now() : null,
            'approved_by'      => $status === 'Approved' ? $request->user()->id : null,
            'status'           => $status === 'Approved' ? 'Active' : ($status === 'Rejected' ? 'Rejected' : $worker->status),
        ]);

        return response()->json(['status' => 'success', 'message' => "Worker decision updated to {$status}."]);
    }

    public function scanAccess(Request $request, string $token)
    {
        return response()->json($this->workerService->getPublicScan($token));
    }

    public function destroy(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $this->workerService->destroy($worker);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->workerService->stats($request->user()->tenant_id));
    }

    private function assertTenant(Request $request, TpvWorker $worker): void
    {
        $tenantId = $request->user()?->tenant_id ?? 1;
        if (empty($worker->tenant_id)) {
            $worker->update(['tenant_id' => $tenantId]);
            return;
        }

        if ((int) $worker->tenant_id !== (int) $tenantId) {
            $worker->update(['tenant_id' => $tenantId]);
        }
    }
}
