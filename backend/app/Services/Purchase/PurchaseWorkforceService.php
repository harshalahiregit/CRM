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
use App\Support\Medical\MedicalWorkflow;
use App\Support\Shared\WorkerImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private PurchaseWorkerRepository $workers) {}

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
            'tenant_id' => $vendor->tenant_id,
            'purchase_vendor_id' => $vendor->id,
            'status' => $data['status'] ?? 'Pending',
        ]));

        $worker->update(['worker_code' => $this->makeCode($vendor, $worker->id)]);
        Log::channel('purchase')->info('Purchase worker created', ['worker_id' => $worker->id, 'vendor_id' => $vendor->id]);

        return $worker->fresh(['documents', 'medicals', 'trainings', 'inductions', 'latestMedical', 'latestInduction']);
    }

    /**
     * Register many workers from one sheet — TPV's importer, for Purchase.
     *
     * A vendor arriving with forty people had to register them one at a time,
     * because Purchase never had this at all. The column order is TPV's, so the
     * same template works for both engines and nobody has to keep two.
     *
     *   name · gender · dob · mobile · blood group · designation · skill · id · photo
     *
     * Reading the file is shared (see WorkerImport, which knows about Excel's
     * BOM, its scientific-notation Aadhaar, four date formats and ZIP photos);
     * the writing is Purchase's own, against purchase_workers.
     *
     * Every skipped row is NAMED. A bare count is indistinguishable from an
     * import that quietly failed, which is how a vendor ends up believing forty
     * people are registered when none are.
     */
    public function bulkUpload(mixed $file, PurchaseVendor $vendor): array
    {
        ['rows' => $rows, 'photos' => $photos, 'cleanup' => $cleanup] = WorkerImport::read($file);

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $duplicates = [];

        try {
            foreach ($rows as $i => $row) {
                $rowNo = $i + 2;                       // +1 for the header, +1 for 1-based
                $name = trim($row[0] ?? '');
                if ($name === '') {
                    continue;                          // a blank line is not a failure
                }

                $gender = ucfirst(strtolower(trim($row[1] ?? 'Male')));
                $dob = WorkerImport::parseDate($row[2] ?? null);
                $phone = preg_replace('/\D/', '', trim($row[3] ?? ''));
                $blood = trim($row[4] ?? '');
                $designation = trim($row[5] ?? 'Worker');
                $skill = trim($row[6] ?? 'Unskilled');
                $idNumber = trim($row[7] ?? '');
                $photoRef = trim($row[8] ?? '');

                if ($problem = WorkerImport::aadhaarProblem($idNumber, $rowNo)) {
                    $errors[] = $problem;
                    $skipped++;

                    continue;
                }

                // Already on this vendor's books? Identify by the id number when
                // there is one, otherwise by name and mobile together — a name
                // alone would refuse two genuine namesakes.
                $existing = PurchaseWorker::where('tenant_id', $vendor->tenant_id)
                    ->where('purchase_vendor_id', $vendor->id)
                    ->when($idNumber !== '', fn ($q) => $q->where('id_proof_number', $idNumber))
                    ->when($idNumber === '', function ($q) use ($name, $phone) {
                        $q->where('full_name', $name);
                        if ($phone !== '') {
                            $q->where('phone', $phone);
                        }
                    })
                    ->exists();

                if ($existing) {
                    $duplicates[] = "Row {$rowNo}: {$name} is already registered under this vendor.";
                    $skipped++;

                    continue;
                }

                $worker = PurchaseWorker::create([
                    'tenant_id' => $vendor->tenant_id,
                    'purchase_vendor_id' => $vendor->id,
                    'full_name' => $name,
                    'gender' => $gender,
                    'dob' => $dob,
                    'phone' => $phone ?: null,
                    'blood_group' => $blood ?: null,
                    'designation' => $designation,
                    'skill_category' => $skill,
                    'id_proof_number' => $idNumber ?: null,
                    'photo_path' => WorkerImport::storePhoto($photos, [$photoRef, $idNumber, $phone, str_replace(' ', '_', $name), $name]),
                    'status' => 'Pending',
                    'current_step' => 1,
                ]);
                $worker->update(['worker_code' => $this->makeCode($vendor, $worker->id)]);

                $inserted++;
            }
        } finally {
            $cleanup();
        }

        Log::channel('purchase')->info('Purchase workers bulk imported', [
            'vendor_id' => $vendor->id, 'inserted' => $inserted, 'skipped' => $skipped,
        ]);

        return WorkerImport::summarise($inserted, $skipped, $duplicates, $errors);
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
            'tenant_id' => $worker->tenant_id,
            'purchase_vendor_id' => $worker->purchase_vendor_id,
            'purchase_worker_id' => $worker->id,
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'uploaded',
        ]);
    }

    /**
     * Step 2 — medical.
     *
     * Delegated to the Medical module so a certificate keyed in here is the same
     * kind of thing as one a doctor filed: it gets a certificate number, a
     * health score, a place in the quality-check queue and a timeline. Having a
     * second, quieter way to create a medical would mean a worker could be
     * cleared without anyone reviewing the certificate.
     */
    public function saveMedical(PurchaseWorker $worker, array $data, User|PurchaseVendor|null $actor = null): PurchaseWorkerMedical
    {
        $actor ??= request()?->user();

        return app(PurchaseMedicalWorkflowService::class)->record(
            $worker,
            $data,
            $actor,
            $actor instanceof PurchaseVendor
                ? MedicalWorkflow::ORIGIN_VENDOR_UPLOAD
                : MedicalWorkflow::ORIGIN_ADMIN,
        );
    }

    public function saveTraining(PurchaseWorker $worker, array $data): PurchaseWorkerTraining
    {
        // Typed catalogue (§15). training_type is optional — a legacy free-text
        // title still records a valid training; an unknown type is normalised to
        // 'Other' so the row always carries a catalogue key when one is supplied.
        $type = $data['training_type'] ?? null;
        if ($type !== null && ! in_array($type, PurchaseWorkerTraining::TYPES, true)) {
            $type = 'Other';
        }

        $training = PurchaseWorkerTraining::create(array_merge($this->tenantKeys($worker), [
            'title' => $data['title'] ?? ($type ? str_replace('_', ' ', $type) : null),
            'training_type' => $type,
            'provider' => $data['provider'] ?? null,
            'training_date' => $data['training_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            // TPV-parity currency window; falls back to expiry_date when absent.
            'valid_until' => $data['valid_until'] ?? ($data['expiry_date'] ?? null),
            'status' => $data['status'] ?? 'Pending',
            'score' => $data['score'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]));

        $this->advanceTo($worker, 3, $this->stepThreeCleared($worker->fresh()));

        return $training;
    }

    public function saveInduction(PurchaseWorker $worker, array $data): PurchaseWorkerInduction
    {
        // The Medical module's prerequisite block, mirroring TPV: no safety
        // induction until medical clearance exists. One verdict answers it, so
        // this refusal and the "Medical Report is Pending" banner always say the
        // same thing — including where the project bypass makes it moot.
        $medicalWorkflow = app(PurchaseMedicalWorkflowService::class);
        $clearance = $medicalWorkflow->clearanceFor($worker);
        if ($clearance['required'] && ! $clearance['cleared']
            && ($medicalWorkflow->config($worker->tenant_id)['block_induction'] ?? true)) {
            throw new BusinessException('Safety induction is blocked — '.$clearance['message']);
        }

        $induction = PurchaseWorkerInduction::create(array_merge($this->tenantKeys($worker), [
            'induction_date' => $data['induction_date'] ?? null,
            'status' => $data['status'] ?? 'Pending',
            'conducted_by' => $data['conducted_by'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            // Session depth (TPV parity). training_date falls back to the
            // induction date: a session recorded on the day it ran is the common
            // case, and leaving it null would lose when it was actually delivered.
            'recorded_by' => $data['recorded_by'] ?? null,
            'trainer_name' => $data['trainer_name'] ?? null,
            'training_date' => $data['training_date'] ?? ($data['induction_date'] ?? null),
            'valid_until' => $data['valid_until'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'topics' => $data['topics'] ?? null,
            'score' => $data['score'] ?? null,
            'passed' => $data['passed'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
            'signature_path' => $data['signature_path'] ?? null,
            'thumbprint_path' => $data['thumbprint_path'] ?? null,
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
    /**
     * Step 2 clears on medical CLEARANCE, never merely on a medical existing:
     * recording an Unfit — or an unreviewed — examination is a valid thing to
     * do, it just is not progress, and the pointer must not claim it is.
     *
     * Public because the Medical module records examinations of its own (the
     * doctor portal), and both routes must move the pointer by the same rule.
     */
    public function syncMedicalStep(PurchaseWorker $worker): void
    {
        $this->advanceTo($worker, 2, $this->readiness($worker)['medical_ok']);
    }

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
                'training' => $r['training_ok'],  'induction' => $r['induction_ok'],
                'competency' => $r['competency_ok'],
            ])->reject(fn ($ok) => $ok)->keys()->implode(', ');

            // Name the specific competencies the worker is short of (Rule 4), so the
            // badge refusal says exactly what to record, not just "competency".
            if (! $r['competency_ok'] && ! empty($r['missing_competencies'])) {
                $missing .= ' ('.implode(', ', $r['missing_competencies']).')';
            }

            throw new BusinessException("This worker is not ready for a badge — outstanding: {$missing}.", 422);
        }

        if ((int) $worker->current_step < 4) {
            throw new BusinessException('PPE must be issued before a badge can be activated.', 422);
        }

        // Reaching step 4 says PPE was ISSUED; it does not say the right PPE was
        // issued. With a requirement matrix configured, the badge refusal names
        // the mandatory items still outstanding — the same rule TPV applies, and
        // the reason this table was added: one pair of gloves used to satisfy a
        // check that should have demanded a helmet and boots by name.
        $missingPpe = app(PurchasePpeService::class)->missingMandatoryFor($worker);
        if ($missingPpe->isNotEmpty()) {
            throw new BusinessException(
                'Mandatory PPE not issued: '.$missingPpe->pluck('name')->implode(', ').'.', 422
            );
        }

        $worker->forceFill([
            'status' => 'Active',
            'current_step' => 5,
            'badge_number' => $this->makeBadgeNumber($worker),
            // The gate scans this, so it must be unguessable and unique.
            'qr_token' => Str::random(48),
            'badge_issued_at' => now(),
            'badge_issued_by' => $actor->id,
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
            'status' => 'Terminated',
            'qr_token' => null,
            'notes' => $this->appendNote($worker, "Terminated: {$reason}"),
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

        // PPE at the gate (mirror of TPV Rule 5): warn (default) / deny / off.
        // The tenant's Settings value wins when they have set it; otherwise the
        // deployment config/env default applies. Wrapped so a PPE-subsystem hiccup
        // can never turn away an otherwise-clear worker.
        $settings = app(PurchaseSettingService::class);
        $mode = $settings->isConfigured((int) $worker->tenant_id, 'gate_ppe_enforcement')
            ? $settings->get((int) $worker->tenant_id, 'gate_ppe_enforcement')
            : config('purchase.gate.ppe_enforcement', 'warn');
        if ($mode !== 'off') {
            try {
                // With a requirement matrix configured this names the MISSING
                // items; without one it falls back to "anything at all", so a
                // tenant that has not filled the matrix in sees no change.
                $ppe = app(PurchasePpeService::class);
                $missing = $ppe->missingMandatoryFor($worker);
                $shortfall = $missing->isNotEmpty()
                    ? 'Mandatory PPE not issued: '.$missing->pluck('name')->implode(', ').'.'
                    : ($ppe->heldBy($worker)->isEmpty() ? 'No PPE has been issued to this worker.' : null);

                if ($shortfall) {
                    if ($mode === 'deny') {
                        return ['admit' => false, 'reason' => $shortfall];
                    }

                    return ['admit' => true, 'reason' => null, 'badge_number' => $worker->badge_number,
                        'warning' => $shortfall];
                }
            } catch (\Throwable $e) {
                Log::channel('purchase')->warning('Gate PPE check skipped', [
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

        $ind = $worker->relationLoaded('latestInduction') ? $worker->latestInduction : $worker->latestInduction()->first();

        $documentsOk = ($worker->documents_count ?? $worker->documents()->count()) > 0;
        // Medical clears on the module's clearance verdict — a passing, current
        // certificate that the quality team has ACCEPTED, or a project where
        // medical does not apply at all. A signed-but-unreviewed certificate is
        // deliberately not readiness.
        $medicalClearance = app(PurchaseMedicalWorkflowService::class)->clearanceFor($worker);
        $medicalOk = $medicalClearance['cleared'];
        // Training clears when a Completed, unexpired record exists — honouring the
        // TPV-parity valid_until window and the legacy expiry_date alike. Typed or
        // free-text titles both count.
        $trainingOk = $worker->trainings()
            ->where('status', 'Completed')
            ->where(function ($q) use ($today) {
                $q->where(function ($q) use ($today) {
                    $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today);
                })->where(function ($q) use ($today) {
                    $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
                });
            })
            ->exists();
        $inductionOk = $ind && $ind->status === 'Completed';

        // "No Competency, No Work" (mirror of TPV Rule 4). Purchase carries no
        // per-activity required_competency, so the requirement is the site-wide
        // Settings list (workforce_required_competencies). missingFor() returns an
        // empty collection when nothing is required, so competency_ok defaults true
        // and the gate degrades gracefully — it only bites once a tenant configures
        // a requirement.
        $missingComp = app(PurchaseCompetencyService::class)->missingFor($worker);
        $competencyOk = $missingComp->isEmpty();

        $checks = [$documentsOk, $medicalOk, $trainingOk, $inductionOk, $competencyOk];
        $passed = count(array_filter($checks));

        return [
            'documents_ok' => $documentsOk,
            'medical_ok' => $medicalOk,
            // The reason, for the dashboard banner.
            'medical_clearance' => $medicalClearance,
            'training_ok' => $trainingOk,
            'induction_ok' => $inductionOk,
            'competency_ok' => $competencyOk,
            'missing_competencies' => $missingComp->all(),
            // The full PPE checklist, so a blocked badge can say WHICH items are
            // missing rather than "PPE". `configured` tells "fully equipped"
            // apart from "no matrix has been set up yet".
            'ppe_compliance' => app(PurchasePpeService::class)->complianceFor($worker),
            'ready' => $passed === count($checks),
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
            $medical += $r['medical_ok'] ? 1 : 0;
            $training += $r['training_ok'] ? 1 : 0;
            $induction += $r['induction_ok'] ? 1 : 0;
            $ready += $r['ready'] ? 1 : 0;
            $readinessSum += $r['readiness_pct'];
        }
        $pct = fn ($n) => (int) round(($n / $count) * 100);

        return [
            'worker_count' => $count,
            'ready_count' => $ready,
            'medical_pct' => $pct($medical),
            'training_pct' => $pct($training),
            'induction_pct' => $pct($induction),
            'readiness_pct' => (int) round($readinessSum / $count),
            'ready' => $ready === $count,
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
            'tenant_id' => $worker->tenant_id,
            'purchase_vendor_id' => $worker->purchase_vendor_id,
            'purchase_worker_id' => $worker->id,
        ];
    }

    private function cleanWorker(array $data): array
    {
        return collect($data)->only([
            'full_name', 'gender', 'dob', 'phone', 'email', 'designation',
            'id_proof_type', 'id_proof_number', 'address', 'city', 'state', 'pincode', 'status', 'notes',
            'photo_path',
            // TPV-parity identity + employment depth. This whitelist is what
            // actually reaches the model, so a column added to $fillable but not
            // listed here is accepted by the request and then silently dropped.
            'blood_group', 'skill_category', 'trade', 'age_reason',
            'emergency_contact', 'emergency_phone', 'bocw_number',
            'experience_years', 'joining_date', 'exit_date',
            'project', 'site', 'department',
        ])->filter(fn ($v) => $v !== null)->all();
    }

    private function makeCode(PurchaseVendor $vendor, int $id): string
    {
        return 'PW-'.str_pad((string) $vendor->id, 3, '0', STR_PAD_LEFT).'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
