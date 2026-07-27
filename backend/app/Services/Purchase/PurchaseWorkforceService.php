<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerDocument;
use App\Models\Purchase\PurchaseWorkerInduction;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Purchase\PurchaseWorkerTraining;
use App\Repositories\Purchase\PurchaseWorkerRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Purchase-owned workforce engine — worker CRUD, documents, medical, training,
 * induction and readiness. Every method is vendor-scoped (purchase_vendor_id) by
 * the caller; this service never trusts an id from the request body. Independent
 * of the TPV worker engine and the shared Vendor master.
 */
class PurchaseWorkforceService
{
    private const DISK = 'purchase_docs';

    public function __construct(private PurchaseWorkerRepository $workers)
    {
    }

    /* ── Workers ─────────────────────────────────────────────────────────── */

    public function list(PurchaseVendor $vendor, array $filters = []): array
    {
        return $this->workers->listForVendor($vendor->tenant_id, $vendor->id, $filters)
            ->map(fn ($w) => $this->workerPayload($w))->all();
    }

    public function find(PurchaseVendor $vendor, int $id): ?PurchaseWorker
    {
        return $this->workers->findForVendor($id, $vendor->tenant_id, $vendor->id);
    }

    public function create(PurchaseVendor $vendor, array $data): PurchaseWorker
    {
        $worker = PurchaseWorker::create(array_merge($this->cleanWorker($data), [
            'tenant_id'          => $vendor->tenant_id,
            'purchase_vendor_id' => $vendor->id,
            'status'             => $data['status'] ?? 'Pending',
        ]));

        $worker->update(['worker_code' => $this->makeCode($vendor, $worker->id)]);
        Log::channel('purchase')->info('Purchase worker created', ['worker_id' => $worker->id, 'vendor_id' => $vendor->id]);

        return $worker->fresh(['documents', 'medicals', 'trainings', 'inductions', 'latestMedical', 'latestInduction']);
    }

    public function update(PurchaseWorker $worker, array $data): PurchaseWorker
    {
        $worker->update($this->cleanWorker($data));

        return $worker->fresh(['documents', 'medicals', 'trainings', 'inductions', 'latestMedical', 'latestInduction']);
    }

    public function delete(PurchaseWorker $worker): void
    {
        $worker->delete();
    }

    /* ── Sub-records ─────────────────────────────────────────────────────── */

    public function addDocument(PurchaseWorker $worker, string $type, UploadedFile $file): PurchaseWorkerDocument
    {
        $path = $file->storeAs(
            "tenant-{$worker->tenant_id}/vendor-{$worker->purchase_vendor_id}/worker-{$worker->id}",
            'doc-'.Str::random(12).'.'.$file->getClientOriginalExtension(),
            self::DISK,
        );

        return PurchaseWorkerDocument::create([
            'tenant_id'          => $worker->tenant_id,
            'purchase_vendor_id' => $worker->purchase_vendor_id,
            'purchase_worker_id' => $worker->id,
            'type'               => $type,
            'original_name'      => $file->getClientOriginalName(),
            'file_path'          => $path,
            'status'             => 'uploaded',
        ]);
    }

    public function saveMedical(PurchaseWorker $worker, array $data): PurchaseWorkerMedical
    {
        return PurchaseWorkerMedical::create(array_merge($this->tenantKeys($worker), [
            'exam_date'      => $data['exam_date'] ?? null,
            'expiry_date'    => $data['expiry_date'] ?? null,
            'fitness_status' => $data['fitness_status'] ?? 'Pending',
            'blood_group'    => $data['blood_group'] ?? null,
            'remarks'        => $data['remarks'] ?? null,
        ]));
    }

    public function saveTraining(PurchaseWorker $worker, array $data): PurchaseWorkerTraining
    {
        return PurchaseWorkerTraining::create(array_merge($this->tenantKeys($worker), [
            'title'         => $data['title'],
            'training_date' => $data['training_date'] ?? null,
            'expiry_date'   => $data['expiry_date'] ?? null,
            'status'        => $data['status'] ?? 'Pending',
            'score'         => $data['score'] ?? null,
            'remarks'       => $data['remarks'] ?? null,
        ]));
    }

    public function saveInduction(PurchaseWorker $worker, array $data): PurchaseWorkerInduction
    {
        return PurchaseWorkerInduction::create(array_merge($this->tenantKeys($worker), [
            'induction_date' => $data['induction_date'] ?? null,
            'status'         => $data['status'] ?? 'Pending',
            'conducted_by'   => $data['conducted_by'] ?? null,
            'remarks'        => $data['remarks'] ?? null,
        ]));
    }

    /* ── Readiness & progress ────────────────────────────────────────────── */

    /** Per-worker readiness breakdown. */
    public function readiness(PurchaseWorker $worker): array
    {
        $today = now()->startOfDay();

        $med = $worker->relationLoaded('latestMedical') ? $worker->latestMedical : $worker->latestMedical()->first();
        $ind = $worker->relationLoaded('latestInduction') ? $worker->latestInduction : $worker->latestInduction()->first();

        $documentsOk = ($worker->documents_count ?? $worker->documents()->count()) > 0;
        $medicalOk   = $med && $med->fitness_status === 'Fit' && (! $med->expiry_date || $med->expiry_date->gte($today));
        $trainingOk  = $worker->trainings()->where('status', 'Completed')->exists();
        $inductionOk = $ind && $ind->status === 'Completed';

        $checks = [$documentsOk, $medicalOk, $trainingOk, $inductionOk];
        $passed = count(array_filter($checks));

        return [
            'documents_ok'  => $documentsOk,
            'medical_ok'    => $medicalOk,
            'training_ok'   => $trainingOk,
            'induction_ok'  => $inductionOk,
            'ready'         => $passed === count($checks),
            'readiness_pct' => (int) round(($passed / count($checks)) * 100),
        ];
    }

    /** Vendor-level workforce summary for the dashboard / onboarding gate. */
    public function summary(PurchaseVendor $vendor): array
    {
        $workers = $this->workers->listForVendor($vendor->tenant_id, $vendor->id);
        $count = $workers->count();

        if ($count === 0) {
            return ['worker_count' => 0, 'ready_count' => 0, 'medical_pct' => 0, 'training_pct' => 0, 'induction_pct' => 0, 'readiness_pct' => 0, 'ready' => false];
        }

        $medical = $training = $induction = $ready = 0;
        $readinessSum = 0;
        foreach ($workers as $w) {
            $r = $this->readiness($w);
            $medical   += $r['medical_ok'] ? 1 : 0;
            $training  += $r['training_ok'] ? 1 : 0;
            $induction += $r['induction_ok'] ? 1 : 0;
            $ready     += $r['ready'] ? 1 : 0;
            $readinessSum += $r['readiness_pct'];
        }
        $pct = fn ($n) => (int) round(($n / $count) * 100);

        return [
            'worker_count'  => $count,
            'ready_count'   => $ready,
            'medical_pct'   => $pct($medical),
            'training_pct'  => $pct($training),
            'induction_pct' => $pct($induction),
            'readiness_pct' => (int) round($readinessSum / $count),
            'ready'         => $ready === $count,
        ];
    }

    /** Worker + its readiness, for API responses. */
    public function workerPayload(PurchaseWorker $worker): array
    {
        return array_merge($worker->toArray(), ['readiness' => $this->readiness($worker)]);
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    private function tenantKeys(PurchaseWorker $worker): array
    {
        return [
            'tenant_id'          => $worker->tenant_id,
            'purchase_vendor_id' => $worker->purchase_vendor_id,
            'purchase_worker_id' => $worker->id,
        ];
    }

    private function cleanWorker(array $data): array
    {
        return collect($data)->only([
            'full_name', 'gender', 'dob', 'phone', 'email', 'designation',
            'id_proof_type', 'id_proof_number', 'address', 'city', 'state', 'pincode', 'status', 'notes',
        ])->filter(fn ($v) => $v !== null)->all();
    }

    private function makeCode(PurchaseVendor $vendor, int $id): string
    {
        return 'PW-'.str_pad((string) $vendor->id, 3, '0', STR_PAD_LEFT).'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
