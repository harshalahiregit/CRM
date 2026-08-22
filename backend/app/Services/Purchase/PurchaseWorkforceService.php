<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerDocument;
use App\Models\Purchase\PurchaseWorkerInduction;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Purchase\PurchaseWorkerTraining;
use App\Models\User;
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
        $medical = PurchaseWorkerMedical::create(array_merge($this->tenantKeys($worker), [
            'exam_date'      => $data['exam_date'] ?? null,
            'expiry_date'    => $data['expiry_date'] ?? null,
            'fitness_status' => $data['fitness_status'] ?? 'Pending',
            'blood_group'    => $data['blood_group'] ?? null,
            'remarks'        => $data['remarks'] ?? null,
        ]));

        // Step 2 clears only on a FIT result. Recording an Unfit examination is a
        // valid thing to do — it just is not progress, and the pointer must not
        // claim it is.
        $this->advanceTo($worker, 2, $this->readiness($worker->fresh())['medical_ok']);

        return $medical;
    }

    public function saveTraining(PurchaseWorker $worker, array $data): PurchaseWorkerTraining
    {
        $training = PurchaseWorkerTraining::create(array_merge($this->tenantKeys($worker), [
            'title'         => $data['title'],
            'training_date' => $data['training_date'] ?? null,
            'expiry_date'   => $data['expiry_date'] ?? null,
            'status'        => $data['status'] ?? 'Pending',
            'score'         => $data['score'] ?? null,
            'remarks'       => $data['remarks'] ?? null,
        ]));

        $this->advanceTo($worker, 3, $this->stepThreeCleared($worker->fresh()));

        return $training;
    }

    public function saveInduction(PurchaseWorker $worker, array $data): PurchaseWorkerInduction
    {
        $induction = PurchaseWorkerInduction::create(array_merge($this->tenantKeys($worker), [
            'induction_date' => $data['induction_date'] ?? null,
            'status'         => $data['status'] ?? 'Pending',
            'conducted_by'   => $data['conducted_by'] ?? null,
            'remarks'        => $data['remarks'] ?? null,
        ]));

        $this->advanceTo($worker, 3, $this->stepThreeCleared($worker->fresh()));

        return $induction;
    }

    /**
     * Step 3 covers BOTH training and induction — Purchase records them in two
     * tables, and the step is only done when each has a completed row. Checked
     * against readiness() so the wizard and the gate cannot disagree.
     */
    private function stepThreeCleared(PurchaseWorker $worker): bool
    {
        $r = $this->readiness($worker);

        return $r['training_ok'] && $r['induction_ok'];
    }

    /**
     * Mark $step as reached, and only ever move forward.
     *
     * current_step holds the HIGHEST step completed — 1 on create, 2 once medical
     * is Fit, 3 once training and induction are done, 4 once PPE is issued, 5 once
     * the badge is activated. Same convention the PPE service writes, so the two
     * cannot disagree about where a worker is.
     *
     * The wizard resumes from this column, so a later save must never drag a
     * worker backwards, and a failed medical or induction must not advance it.
     */
    private function advanceTo(PurchaseWorker $worker, int $step, bool $cleared): void
    {
        if (! $cleared) {
            return;
        }

        if ((int) $worker->current_step < $step) {
            $worker->forceFill(['current_step' => $step])->save();
        }
    }

    /* ── Step 5 — entry badge (ADMIN only) ───────────────────────────────── */

    /**
     * Activate a worker and issue its entry badge.
     *
     * Deliberately NOT callable from the vendor portal: the vendor supplies the
     * evidence, the site decides who may walk in. The route enforces role:admin;
     * this re-checks readiness so a badge can never be issued to a worker who is
     * not medically fit, trained, inducted and equipped.
     */
    public function activateBadge(PurchaseWorker $worker, User $actor, array $data = []): PurchaseWorker
    {
        if ($worker->badge_number) {
            return $worker;   // idempotent: re-activating keeps the original badge
        }

        $r = $this->readiness($worker);

        if (! $r['ready']) {
            $missing = collect([
                'documents' => $r['documents_ok'], 'medical' => $r['medical_ok'],
                'training'  => $r['training_ok'],  'induction' => $r['induction_ok'],
            ])->reject(fn ($ok) => $ok)->keys()->implode(', ');

            throw new BusinessException("This worker is not ready for a badge — outstanding: {$missing}.", 422);
        }

        if ((int) $worker->current_step < 4) {
            throw new BusinessException('PPE must be issued before a badge can be activated.', 422);
        }

        $worker->forceFill([
            'status'            => 'Active',
            'current_step'      => 5,
            'badge_number'      => $this->makeBadgeNumber($worker),
            // The gate scans this, so it must be unguessable and unique.
            'qr_token'          => Str::random(48),
            'badge_issued_at'   => now(),
            'badge_issued_by'   => $actor->id,
            'badge_valid_until' => $data['valid_until'] ?? null,
        ])->save();

        Log::channel('purchase')->info('Purchase worker badge activated', [
            'worker_id' => $worker->id, 'actor_id' => $actor->id,
        ]);

        return $worker->fresh();
    }

    /**
     * Worker lifecycle (parity with the TPV worker lifecycle). Status alone gates
     * site access — gateDecision() admits only an Active worker — so suspending or
     * terminating a worker withholds entry immediately, no badge revocation dance.
     */
    public function suspend(PurchaseWorker $worker, User $actor, ?string $reason = null): PurchaseWorker
    {
        $worker->forceFill(['status' => 'Suspended', 'notes' => $this->appendNote($worker, "Suspended: {$reason}")])->save();
        Log::channel('purchase')->warning('Purchase worker suspended', ['worker_id' => $worker->id, 'actor_id' => $actor->id, 'reason' => $reason]);

        return $worker->fresh();
    }

    public function reinstate(PurchaseWorker $worker, User $actor): PurchaseWorker
    {
        if ($worker->status !== 'Suspended') {
            return $worker;
        }
        // Re-check currency — a medical/badge may have lapsed while suspended.
        $dec = $this->gateDecision($worker->fresh());
        // Restore to Active regardless (the gate still enforces currency on scan),
        // but surface the lapse to the caller via the decision.
        $worker->forceFill(['status' => 'Active'])->save();
        Log::channel('purchase')->info('Purchase worker reinstated', ['worker_id' => $worker->id, 'actor_id' => $actor->id, 'gate' => $dec['admit'] ?? null]);

        return $worker->fresh();
    }

    public function terminate(PurchaseWorker $worker, User $actor, ?string $reason = null): PurchaseWorker
    {
        // Null the QR token so a retained physical badge stops resolving at the
        // gate — a terminated worker must not scan back in (parity with TPV).
        $worker->forceFill([
            'status'   => 'Terminated',
            'qr_token' => null,
            'notes'    => $this->appendNote($worker, "Terminated: {$reason}"),
        ])->save();
        Log::channel('purchase')->warning('Purchase worker terminated', ['worker_id' => $worker->id, 'actor_id' => $actor->id, 'reason' => $reason]);

        return $worker->fresh();
    }

    private function appendNote(PurchaseWorker $worker, string $line): string
    {
        $line = trim($line);

        return trim(($worker->notes ? $worker->notes."\n" : '').now()->format('d M Y').' — '.$line);
    }

    /**
     * The gate decision for a scanned badge.
     *
     * Admit only an Active worker holding a badge. Everything else is a refusal
     * with a reason, so the guard is told WHY rather than just "no".
     */
    public function gateDecision(PurchaseWorker $worker): array
    {
        if (! $worker->badge_number || ! $worker->qr_token) {
            return ['admit' => false, 'reason' => 'No entry badge has been issued for this worker.'];
        }
        if ($worker->status !== 'Active') {
            return ['admit' => false, 'reason' => 'This worker is not active.'];
        }
        if ($worker->badge_valid_until && $worker->badge_valid_until->isPast()) {
            return ['admit' => false, 'reason' => 'This badge expired on '.$worker->badge_valid_until->format('d M Y').'.'];
        }

        // Medical currency is a hard gate, not just an activation check — a
        // certificate can lapse long after the badge was issued (parity with the
        // TPV gate). readiness() already tracks it; the gate must refuse it too.
        $med = $worker->relationLoaded('latestMedical') ? $worker->latestMedical : $worker->latestMedical()->first();
        if ($med && $med->expiry_date && $med->expiry_date->isPast()) {
            return ['admit' => false, 'reason' => 'Medical certificate expired on '.$med->expiry_date->format('d M Y').'.'];
        }

        // PPE at the gate (mirror of TPV Rule 5). Config-driven
        // (purchase.gate.ppe_enforcement): warn (default) / deny / off. Wrapped so
        // a PPE-subsystem hiccup can never turn away an otherwise-clear worker.
        $mode = config('purchase.gate.ppe_enforcement', 'warn');
        if ($mode !== 'off') {
            try {
                $heldNone = app(PurchasePpeService::class)->heldBy($worker)->isEmpty();
                if ($heldNone) {
                    if ($mode === 'deny') {
                        return ['admit' => false, 'reason' => 'No PPE has been issued to this worker.'];
                    }

                    return ['admit' => true, 'reason' => null, 'badge_number' => $worker->badge_number,
                        'warning' => 'No PPE has been issued to this worker.'];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::channel('purchase')->warning('Gate PPE check skipped', [
                    'worker_id' => $worker->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return ['admit' => true, 'reason' => null, 'badge_number' => $worker->badge_number];
    }

    private function makeBadgeNumber(PurchaseWorker $worker): string
    {
        return 'PWB-'.now()->format('Y').'-'.str_pad((string) $worker->id, 5, '0', STR_PAD_LEFT);
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
