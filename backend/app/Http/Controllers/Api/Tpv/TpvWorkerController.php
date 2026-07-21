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
        $worker = $this->workerService->create($request->validated(), $request->user());

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
        abort_unless(
            (int) $worker->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Worker not found'
        );
    }
}
