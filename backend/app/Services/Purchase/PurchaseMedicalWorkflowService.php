<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseMedicalBulkBatch;
use App\Models\Purchase\PurchaseMedicalMessage;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorkPackage;
use App\Models\User;
use App\Services\Medical\MedicalGeoResolver;
use App\Services\NotificationService as Bell;
use App\Services\Notifications\NotificationService as Channels;
use App\Support\FrontendUrl;
use App\Support\Medical\HealthScore;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Purchase\PurchaseMedicalFitness as Fitness;
use App\Support\Spreadsheet;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The medical lifecycle on the Purchase side — the mirror of
 * TpvMedicalWorkflowService, against the Purchase register.
 *
 * The two are intentionally not one class: the tables, the vendor identity and
 * the settings store all differ (a Purchase vendor logs in as a PurchaseVendor,
 * not as a User, so it is reachable by e-mail rather than by an in-app bell).
 * What IS shared is the vocabulary and the scoring — App\Support\Medical — so
 * the two sides can never disagree about what "on hold" or a 7.5 means.
 */
class PurchaseMedicalWorkflowService
{
    public function __construct(
        private PurchaseSettingService $settings,
        private MedicalGeoResolver $geo,
        private Bell $bell,
        private Channels $channels,
    ) {}

    /* ── Configuration ──────────────────────────────────────────────────── */

    /**
     * The flat Purchase settings keys, assembled into the same shape the TPV
     * side reads, so callers on either side speak one language.
     */
    public function config(?int $tenantId): array
    {
        $all = $this->settings->all((int) $tenantId);

        $reasons = json_decode((string) ($all['medical_reasons'] ?? ''), true);

        return [
            'validity_months'        => (int) ($all['medical_validity_months'] ?? 12),
            'auto_approve_internal'  => (bool) ($all['medical_auto_approve_internal'] ?? false),
            'max_iterations'         => (int) ($all['medical_max_iterations'] ?? 10),
            'block_induction'        => (bool) ($all['medical_block_induction'] ?? true),
            'pending_message'        => (string) ($all['medical_pending_message'] ?? 'Medical Report is Pending'),
            'not_applicable_default' => (bool) ($all['medical_not_applicable_default'] ?? false),
            'qc_approver_ids'        => array_values(array_filter(array_map(
                'intval', explode(',', (string) ($all['medical_qc_approver_ids'] ?? ''))
            ))),
            'reasons'                => is_array($reasons) && $reasons ? $reasons : MedicalQcStatus::defaultReasons(),
        ];
    }

    /* ── Recording an examination ───────────────────────────────────────── */

    /**
     * Record an examination. Same rule as the TPV side: a same-day save updates
     * the record in place only while it is still awaiting review, so a decided
     * certificate is never silently rewritten.
     *
     * @param  array<string,mixed>  $data
     */
    public function record(PurchaseWorker $worker, array $data, User|PurchaseVendor|null $actor, string $origin = MedicalWorkflow::ORIGIN_ADMIN, ?PurchaseWorkerMedical $previous = null): PurchaseWorkerMedical
    {
        $config   = $this->config($worker->tenant_id);
        $isReexam = (bool) ($data['is_reexam'] ?? false) || $previous !== null;

        $values = $this->prepare($worker, $data, $actor, $origin, $config);

        $existing = $worker->medicals()
            ->whereDate('exam_date', $values['exam_date'])
            ->orderByDesc('attempt_no')
            ->first();

        $reuse = $existing
            && ! $isReexam
            && ($existing->qc_status === null || $existing->qc_status === MedicalQcStatus::PENDING);

        $medical = DB::transaction(function () use ($worker, $values, $existing, $reuse, $isReexam, $previous, $config) {
            if ($reuse) {
                $existing->update($values);
                $medical = $existing->fresh();
            } else {
                $values['attempt_no'] = $existing ? ((int) $existing->attempt_no + 1) : 1;
                if ($isReexam) {
                    $values['is_reexam'] = true;
                    $values['previous_medical_id'] = $previous?->id
                        ?? $worker->medicals()->orderByDesc('exam_date')->orderByDesc('attempt_no')->first()?->id;
                }
                $medical = $worker->medicals()->create($values);
            }

            if (blank($medical->certificate_no)) {
                $medical->certificate_no = $this->certificateNumber($medical);
                $medical->save();
            }

            if ($medical->qc_status === MedicalQcStatus::PENDING
                && ($config['auto_approve_internal'] ?? false)
                && $origin === MedicalWorkflow::ORIGIN_DOCTOR_PORTAL) {
                $medical->forceFill([
                    'qc_status' => MedicalQcStatus::APPROVED,
                    'qc_by'     => $medical->doctor_user_id,
                    'qc_at'     => now(),
                    'qc_note'   => 'Auto-approved: internal examination.',
                ])->save();
            }

            return $medical;
        });

        // The caller is holding this worker instance and will very likely ask it
        // about its medical next (the induction gate does exactly that). Drop
        // the cached relations so it sees the examination just written rather
        // than the absence it loaded a moment ago.
        $worker->unsetRelation('latestMedical')->unsetRelation('medicals');

        // The step pointer is the workforce service's rule, not ours: on this
        // side step 2 clears only on a PASSING medical, so recording an Unfit
        // examination must not read as progress. Asking it keeps one rule
        // whether the examination came from the wizard or the doctor portal.
        app(PurchaseWorkforceService::class)->syncMedicalStep($worker->fresh());

        $this->log($medical, $worker, $isReexam ? MedicalWorkflow::ACTION_REEXAMINED : MedicalWorkflow::ACTION_SUBMITTED, $actor, [
            'body' => ($isReexam ? 'Re-examination' : 'Examination').' recorded'.
                      ($medical->fitness_label ? ' — outcome '.$medical->fitness_label : '').'.',
        ]);

        $who = $this->actorInfo($actor);
        $worker->recordAudit($isReexam ? 'Medical Re-examined' : 'Medical Recorded', $who['user'], null, [
            'fitness' => $medical->fitness_status, 'certificate_no' => $medical->certificate_no,
        ], $who['user'] ? null : $who['name']);

        if ($medical->qc_status === MedicalQcStatus::PENDING) {
            $this->notifyReviewers($medical, $worker, $actor);
        }

        Log::channel('purchase')->info('Purchase medical recorded', [
            'worker_id' => $worker->id, 'medical_id' => $medical->id,
            'origin' => $origin, 'qc' => $medical->qc_status, 'tenant_id' => $worker->tenant_id,
        ]);

        return $medical->fresh();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function prepare(PurchaseWorker $worker, array $data, User|PurchaseVendor|null $actor, string $origin, array $config): array
    {
        unset($data['signature_path'], $data['capture_photo_path'], $data['pdf_path'],
              $data['certificate_no'], $data['qc_status'], $data['qc_by'], $data['qc_at'],
              $data['iteration_count'], $data['system_ip'], $data['geo_place']);

        $data['exam_date'] ??= now()->toDateString();
        $data['exam_date'] = Carbon::parse($data['exam_date'])->toDateString();

        // Purchase carries both columns; expiry_date is what isExpired() reads,
        // so they are written together rather than left to drift apart.
        $data['valid_until'] = $data['valid_until'] ?? $data['expiry_date'] ?? Carbon::parse($data['exam_date'])
            ->copy()->addMonths((int) ($config['validity_months'] ?? 12))->toDateString();
        $data['expiry_date'] = $data['valid_until'];

        $data['screening_band'] = Fitness::bandForScore(
            isset($data['screening_score']) ? (int) $data['screening_score'] : null
        );

        if (($data['report_file'] ?? null) instanceof UploadedFile) {
            $data['document_path'] = $data['report_file']->store(
                'purchase/medical/'.$worker->tenant_id.'/'.$worker->id, 'local'
            );
        }
        unset($data['report_file']);

        foreach ([
            'signature_data' => ['signature_path', 'purchase/workers/signatures/sig_'],
            'capture_photo'  => ['capture_photo_path', 'purchase/workers/medical/photos/photo_'],
        ] as $src => [$column, $prefix]) {
            $decoded = $this->storeDataUrl($data[$src] ?? null, $prefix);
            if ($decoded) {
                $data[$column] = $decoded;
            }
            unset($data[$src]);
        }

        $data['system_ip'] = request()?->ip();
        if (! empty($data['geo_location'])) {
            $data['geo_place'] = $this->geo->resolve($data['geo_location']);
        }

        if ($origin === MedicalWorkflow::ORIGIN_DOCTOR_PORTAL && $actor instanceof User) {
            $profile = $actor->doctorProfile;
            $data['doctor_user_id']       = $actor->id;
            $data['doctor_license_no']    = $profile?->license_no;
            $data['doctor_council']       = $profile?->council;
            $data['doctor_qualification'] = $profile?->qualification;
            $data['examiner_name']      ??= $actor->name;
            $data['clinic_name']        ??= $profile?->clinic_name;
            $data['exam_type']          ??= 'internal';
        } else {
            $data['exam_type'] ??= MedicalWorkflow::isExternal($origin) ? 'external' : 'internal';
        }

        if (isset($data['health_score']) && $data['health_score'] !== null && $data['health_score'] !== '') {
            $data['health_score']        = round((float) $data['health_score'], 1);
            $data['health_score_source'] = 'manual';
        } else {
            $computed = HealthScore::compute($data + ['fitness_status' => $data['fitness_status'] ?? null]);
            $data['health_score']        = $computed['score'];
            $data['health_score_source'] = 'auto';
        }

        return [
            ...$data,
            'tenant_id'          => $worker->tenant_id,
            'purchase_vendor_id' => $worker->purchase_vendor_id,
            // Null for a vendor upload: these columns name a USER, and a vendor
            // is not one. The timeline records who really filed it.
            'recorded_by'        => $this->actorInfo($actor)['id'],
            'created_by'         => $this->actorInfo($actor)['id'],
            'origin'             => $origin,
            'qc_status'          => MedicalQcStatus::PENDING,
        ];
    }

    /* ── Quality check ──────────────────────────────────────────────────── */

    /** @param  array{reason_code?:string|null, note?:string|null}  $opts */
    public function decide(PurchaseWorkerMedical $medical, string $decision, array $opts, User $actor): PurchaseWorkerMedical
    {
        if (! in_array($decision, MedicalQcStatus::DECISIONS, true)) {
            throw new BusinessException('Unknown quality-check decision.');
        }
        if ($medical->qc_status === MedicalQcStatus::REJECTED) {
            throw new BusinessException('This certificate was already rejected. Record a re-examination instead.');
        }
        if (in_array($decision, MedicalQcStatus::REASON_REQUIRED, true) && blank($opts['reason_code'] ?? null) && blank($opts['note'] ?? null)) {
            throw new BusinessException('A reason is required to '.strtolower($decision).' a certificate.');
        }
        $this->assertReviewer($actor, (int) $medical->tenant_id);

        $worker = $medical->worker;

        $medical->forceFill([
            'qc_status'      => $decision,
            'qc_by'          => $actor->id,
            'qc_at'          => now(),
            'qc_reason_code' => $opts['reason_code'] ?? null,
            'qc_note'        => $opts['note'] ?? null,
            'approved_by'    => $decision === MedicalQcStatus::APPROVED ? $actor->id : $medical->approved_by,
            'approved_at'    => $decision === MedicalQcStatus::APPROVED ? now() : $medical->approved_at,
        ])->save();

        $action = match ($decision) {
            MedicalQcStatus::APPROVED => MedicalWorkflow::ACTION_APPROVED,
            MedicalQcStatus::REJECTED => MedicalWorkflow::ACTION_REJECTED,
            default                   => MedicalWorkflow::ACTION_HOLD,
        };

        $this->log($medical, $worker, $action, $actor, [
            'reason_code' => $opts['reason_code'] ?? null,
            'body'        => $opts['note'] ?? null,
        ]);

        $worker?->recordAudit('Medical '.$decision, $actor, null, [
            'certificate_no' => $medical->certificate_no, 'reason' => $opts['reason_code'] ?? null,
        ]);

        $this->notifySubmitter($medical, $worker, $actor, $decision, $opts);

        Log::channel('purchase')->info('Purchase medical quality check', [
            'medical_id' => $medical->id, 'decision' => $decision,
            'by' => $actor->id, 'tenant_id' => $medical->tenant_id,
        ]);

        return $medical->fresh();
    }

    /** @param  array<string,mixed>  $data */
    public function resubmit(PurchaseWorkerMedical $medical, array $data, User|PurchaseVendor $actor): PurchaseWorkerMedical
    {
        if (! MedicalQcStatus::isResubmittable($medical->qc_status)) {
            throw new BusinessException(
                $medical->qc_status === MedicalQcStatus::REJECTED
                    ? 'A rejected certificate cannot be resubmitted — a re-examination is required.'
                    : 'This certificate is not awaiting a response.'
            );
        }

        $max = (int) ($this->config($medical->tenant_id)['max_iterations'] ?? 10);
        if ((int) $medical->iteration_count >= $max) {
            throw new BusinessException("This certificate has reached the limit of {$max} exchanges. The quality team must now approve or reject it.");
        }

        $update = ['qc_status' => MedicalQcStatus::PENDING, 'iteration_count' => (int) $medical->iteration_count + 1];

        if (($data['report_file'] ?? null) instanceof UploadedFile) {
            $update['document_path'] = $data['report_file']->store(
                'purchase/medical/'.$medical->tenant_id.'/'.$medical->purchase_worker_id, 'local'
            );
        }
        foreach (['valid_until', 'exam_date', 'fitness_status', 'examiner_name', 'clinic_name', 'doctor_license_no', 'restrictions'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $update[$field] = $data[$field];
            }
        }
        if (isset($update['valid_until'])) {
            $update['expiry_date'] = $update['valid_until'];
        }

        $medical->forceFill($update)->save();

        $this->log($medical, $medical->worker, MedicalWorkflow::ACTION_RESUBMITTED, $actor, [
            'body' => $data['message'] ?? 'Certificate resubmitted for review.',
        ]);

        $this->notifyReviewers($medical->fresh(), $medical->worker, $actor);

        return $medical->fresh();
    }

    /** A remark on the current round — does not advance the exchange counter. */
    public function comment(PurchaseWorkerMedical $medical, string $body, ?UploadedFile $file, User|PurchaseVendor $actor): PurchaseMedicalMessage
    {
        $attachment = $file?->store('purchase/medical/'.$medical->tenant_id.'/'.$medical->purchase_worker_id, 'local');

        $message = $this->log($medical, $medical->worker, MedicalWorkflow::ACTION_COMMENT, $actor, [
            'body'            => $body,
            'attachment_path' => $attachment,
            'attachment_name' => $file?->getClientOriginalName(),
        ]);

        if ($this->actorInfo($actor)['side'] === MedicalWorkflow::SIDE_QUALITY) {
            $this->notifySubmitter($medical, $medical->worker, $actor, null, ['note' => $body]);
        } else {
            $this->notifyReviewers($medical, $medical->worker, $actor);
        }

        return $message;
    }

    /* ── History and clearance ──────────────────────────────────────────── */

    public function history(PurchaseWorker $worker): array
    {
        $records = $worker->medicals()
            ->with(['doctor:id,name', 'reviewer:id,name'])
            ->orderByDesc('exam_date')->orderByDesc('attempt_no')
            ->get();

        $latest = $records->first();
        $scores = $records->pluck('health_score')->filter()->values();

        return [
            'records'      => $records,
            'latest'       => $latest,
            'health_score' => $latest?->health_score,
            'health_band'  => HealthScore::band($latest?->health_score),
            'score_scale'  => HealthScore::MAX,
            'score_trend'  => $scores->count() >= 2 ? round((float) $scores[0] - (float) $scores[1], 1) : null,
            'exam_count'   => $records->count(),
            'clearance'    => $this->clearanceFor($worker),
        ];
    }

    /**
     * @return array{required:bool, cleared:bool, bypassed:bool, status:string, message:string, medical_id:int|null}
     */
    public function clearanceFor(PurchaseWorker $worker): array
    {
        $config = $this->config($worker->tenant_id);

        if ($this->isBypassed($worker, $config)) {
            return [
                'required' => false, 'cleared' => true, 'bypassed' => true,
                'status' => 'not_applicable',
                'message' => 'Medical is not applicable for this project.',
                'owner' => \App\Support\Shared\MedicalClearanceMessage::OWNER_NONE,
                'action' => null,
                'medical_id' => null,
            ];
        }

        // Read through the relation only when it is already loaded — a stale
        // "no medical" would refuse an induction the worker has earned.
        $medical = $worker->relationLoaded('latestMedical')
            ? $worker->latestMedical
            : $worker->latestMedical()->first();
        $pending = (string) ($config['pending_message'] ?? 'Medical Report is Pending');

        // What is wrong, WHOSE MOVE it is, and what to do about it. The old
        // wording named the state and stopped there — "awaiting quality check"
        // reads as "you still owe us something", so vendors went looking for a
        // missing document that was already submitted.
        $clearance = \App\Support\Shared\MedicalClearanceMessage::for($medical, $pending);

        return [
            'required'   => true,
            'cleared'    => $clearance['status'] === 'approved',
            'bypassed'   => false,
            'status'     => $clearance['status'],
            'message'    => $clearance['message'],
            // Who has to act, and the concrete next step. `action` is null when
            // there is nothing for the vendor to do — which is itself the answer.
            'owner'      => $clearance['owner'],
            'action'     => $clearance['action'],
            'medical_id' => $medical?->id,
        ];
    }

    /** The work package decides; only where it is silent does the tenant default apply. */
    public function isBypassed(PurchaseWorker $worker, ?array $config = null): bool
    {
        if ($worker->work_package_id) {
            $flag = PurchaseWorkPackage::whereKey($worker->work_package_id)->value('medical_not_applicable');
            if ($flag !== null) {
                return (bool) $flag;
            }
        }

        $config ??= $this->config($worker->tenant_id);

        return (bool) ($config['not_applicable_default'] ?? false);
    }

    /* ── Bulk external certificates ─────────────────────────────────────── */

    /** @return array{headers: list<string>, sample: list<string>, notes: list<string>} */
    public static function template(): array
    {
        return [
            'headers' => [
                'worker_code', 'worker_name', 'exam_date', 'valid_until', 'fitness_status',
                'doctor_name', 'doctor_license_no', 'clinic_name', 'blood_group',
                'height_cm', 'weight_kg', 'bp_systolic', 'bp_diastolic', 'restrictions', 'remarks',
            ],
            'sample' => [
                'PW-0001', 'Ramesh Kumar', '2026-12-01', '2027-11-30', 'Fit',
                'Dr A. Sharma', 'MH-123456', 'City Clinic', 'B+',
                '172', '68', '120', '80', '', 'Annual medical',
            ],
            'notes' => [
                'worker_code must match a worker already registered under your vendor.',
                'fitness_status: '.implode(' / ', Fitness::ALL),
                'Dates are YYYY-MM-DD. Leave valid_until blank to default to one year after the exam.',
                'worker_name is for your reference only — the worker code is what the import matches on.',
                'Attach the certificate files alongside the sheet, each named after the worker code (PW-0001.pdf).',
            ],
        ];
    }

    /** @param  array<int, UploadedFile>  $attachments */
    public function bulkImport(UploadedFile $sheet, ?int $vendorId, User $actor, string $side, array $attachments = []): PurchaseMedicalBulkBatch
    {
        $rows = Spreadsheet::readRows($sheet->getRealPath(), $sheet->getClientOriginalExtension() ?: 'csv');
        if (count($rows) < 2) {
            throw new BusinessException('The file has no data rows.');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows));
        $index  = array_flip($header);
        if (! isset($index['worker_code'])) {
            throw new BusinessException('The sheet must have a worker_code column. Download the template to see the expected columns.');
        }

        $files   = $this->indexAttachments($attachments);
        $created = 0;
        $errors  = [];

        foreach ($rows as $i => $row) {
            $lineNo = $i + 2;
            $get = fn (string $col) => isset($index[$col]) ? trim((string) ($row[$index[$col]] ?? '')) : '';

            $code = $get('worker_code');
            if ($code === '') {
                continue;
            }

            try {
                $worker = PurchaseWorker::forTenant($actor->tenant_id)
                    ->when($vendorId, fn ($q) => $q->where('purchase_vendor_id', $vendorId))
                    ->where('worker_code', $code)
                    ->first();

                if (! $worker) {
                    throw new BusinessException('No worker with this code'.($vendorId ? ' under this vendor' : '').'.');
                }

                $fitness = $get('fitness_status') ?: Fitness::FIT;
                if (! Fitness::isValid($fitness)) {
                    throw new BusinessException("Unknown fitness status '{$fitness}'.");
                }

                $data = array_filter([
                    'exam_date'         => $get('exam_date') ?: null,
                    'valid_until'       => $get('valid_until') ?: null,
                    'fitness_status'    => $fitness,
                    'examiner_name'     => $get('doctor_name') ?: null,
                    'doctor_license_no' => $get('doctor_license_no') ?: null,
                    'clinic_name'       => $get('clinic_name') ?: null,
                    'blood_group'       => $get('blood_group') ?: null,
                    'height_cm'         => $get('height_cm') ?: null,
                    'weight_kg'         => $get('weight_kg') ?: null,
                    'bp_systolic'       => $get('bp_systolic') ?: null,
                    'bp_diastolic'      => $get('bp_diastolic') ?: null,
                    'restrictions'      => $get('restrictions') ?: null,
                    'doctor_remarks'    => $get('remarks') ?: null,
                ], fn ($v) => $v !== null);

                if ($file = ($files[strtolower($code)] ?? null)) {
                    $data['report_file'] = $file;
                }

                $this->record($worker, $data, $actor, MedicalWorkflow::ORIGIN_BULK_UPLOAD);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $lineNo, 'worker_code' => $code, 'error' => $e->getMessage()];
            }
        }

        return PurchaseMedicalBulkBatch::create([
            'tenant_id'          => $actor->tenant_id,
            'purchase_vendor_id' => $vendorId,
            'uploaded_by'        => $actor->id,
            'uploaded_side'      => $side,
            'file_name'          => $sheet->getClientOriginalName(),
            'file_path'          => $sheet->store('purchase/medical/bulk/'.$actor->tenant_id, 'local'),
            'total_rows'         => $created + count($errors),
            'created_count'      => $created,
            'failed_count'       => count($errors),
            'errors'             => $errors,
        ]);
    }

    /* ── Timeline ───────────────────────────────────────────────────────── */

    public function timeline(PurchaseWorkerMedical $medical): array
    {
        $max = (int) ($this->config($medical->tenant_id)['max_iterations'] ?? 10);

        return [
            'messages'        => $medical->messages()->with('author:id,name,role')->get(),
            'iteration_count' => (int) $medical->iteration_count,
            'max_iterations'  => $max,
            'exchanges_left'  => max(0, $max - (int) $medical->iteration_count),
            'can_resubmit'    => MedicalQcStatus::isResubmittable($medical->qc_status)
                                 && (int) $medical->iteration_count < $max,
        ];
    }

    private function log(PurchaseWorkerMedical $medical, ?PurchaseWorker $worker, string $action, User|PurchaseVendor|null $actor, array $extra = []): PurchaseMedicalMessage
    {
        $who = $this->actorInfo($actor);

        return PurchaseMedicalMessage::create([
            'tenant_id'          => $medical->tenant_id,
            'medical_id'         => $medical->id,
            'purchase_worker_id' => $medical->purchase_worker_id,
            'iteration_no'       => max(1, (int) $medical->iteration_count + 1),
            'action'             => $action,
            'author_side'        => $who['side'],
            'author_id'          => $who['id'],
            'author_name'        => $who['name'],
            'reason_code'        => $extra['reason_code'] ?? null,
            'body'               => $extra['body'] ?? null,
            'attachment_path'    => $extra['attachment_path'] ?? null,
            'attachment_name'    => $extra['attachment_name'] ?? null,
        ]);
    }

    /* ── People ─────────────────────────────────────────────────────────── */

    /** @return \Illuminate\Support\Collection<int, User> */
    public function reviewers(int $tenantId)
    {
        $ids = $this->config($tenantId)['qc_approver_ids'] ?? [];

        $query = User::query()->where('tenant_id', $tenantId)->where('status', 'active');

        return $ids
            ? $query->whereIn('id', $ids)->get()
            : $query->where('role', 'admin')->get();
    }

    private function assertReviewer(User $actor, int $tenantId): void
    {
        $ids = $this->config($tenantId)['qc_approver_ids'] ?? [];

        if ($ids && ! in_array($actor->id, $ids, true) && $actor->role !== 'admin') {
            throw new BusinessException('You are not assigned to quality-check medical certificates.');
        }
    }

    /* ── Notifications ──────────────────────────────────────────────────── */

    private function notifyReviewers(PurchaseWorkerMedical $medical, ?PurchaseWorker $worker, User|PurchaseVendor|null $actor): void
    {
        $link  = '/app/purchase/medical?certificate='.$medical->certificate_no;
        $title = 'Medical certificate awaiting quality check';
        $body  = trim(($worker?->full_name ?? 'A worker').' — '.($medical->certificate_no ?? '').' ('.$medical->origin_label.')');

        $actorId = $this->actorInfo($actor)['id'];
        foreach ($this->reviewers((int) $medical->tenant_id) as $reviewer) {
            $this->bell->notify($reviewer->id, (int) $medical->tenant_id, 'purchase_medical_review', $title, $body, $link, $actorId);
        }
    }

    /**
     * A Purchase vendor signs in as a PurchaseVendor, not as a User, so there is
     * no bell to ring — the decision reaches them by e-mail and on the timeline
     * their portal reads. The examining doctor IS a User and gets both.
     */
    private function notifySubmitter(PurchaseWorkerMedical $medical, ?PurchaseWorker $worker, User|PurchaseVendor|null $actor, ?string $decision, array $opts): void
    {
        $vendor = $worker?->vendor;
        $title  = $decision
            ? 'Medical certificate '.strtolower(MedicalQcStatus::label($decision))
            : 'New comment on a medical certificate';
        $body = trim(($worker?->full_name ?? 'Worker').' — '.($medical->certificate_no ?? '').
            (blank($opts['note'] ?? null) ? '' : ': '.$opts['note']));

        if ($medical->doctor_user_id) {
            $this->bell->notify($medical->doctor_user_id, (int) $medical->tenant_id, 'purchase_medical_decision',
                $title, $body, '/doctor/examinations/'.$medical->id, $this->actorInfo($actor)['id']);
        }

        if ($vendor?->email && $decision !== null) {
            $this->channels->emailHtml(
                $vendor->email,
                $title,
                view('emails.medical.decision', [
                    'title'     => $title,
                    'worker'    => $worker?->full_name,
                    'medical'   => $medical,
                    'decision'  => $decision,
                    'note'      => $opts['note'] ?? null,
                    'reason'    => $opts['reason_code'] ?? null,
                    'portalUrl' => FrontendUrl::to('/purchase-portal/workforce'),
                ])->render(),
                ['medical_id' => $medical->id, 'event' => 'medical_'.strtolower($decision)],
                null,
                (int) $medical->tenant_id,
            );
        }
    }

    /* ── Small helpers ──────────────────────────────────────────────────── */

    /**
     * Who is acting, in the terms the record needs.
     *
     * A Purchase vendor signs in as a PurchaseVendor, not a User, so "the actor"
     * is not always a user row. This is the one place that difference is
     * resolved: a vendor contributes a name and a side but no user id, because
     * writing a vendor id into a user column would quietly corrupt every
     * "recorded by" reading afterwards.
     *
     * @return array{user: User|null, id: int|null, name: string, side: string}
     */
    private function actorInfo(User|PurchaseVendor|null $actor): array
    {
        $isUser = $actor instanceof User;

        return [
            'user' => $isUser ? $actor : null,
            'id'   => $isUser ? (int) $actor->id : null,
            'name' => $isUser ? (string) $actor->name : (string) ($actor->company_name ?? 'Vendor'),
            'side' => $isUser
                ? MedicalWorkflow::sideForRole($actor->role)
                : ($actor ? MedicalWorkflow::SIDE_VENDOR : MedicalWorkflow::SIDE_SYSTEM),
        ];
    }

    /** MED-PUR-2026-000123 — readable, sortable, unique by construction. */
    private function certificateNumber(PurchaseWorkerMedical $medical): string
    {
        $prefix = (string) config('medical.certificate.prefix', 'MED');

        return sprintf('%s-PUR-%d-%06d', $prefix, Carbon::parse($medical->exam_date)->year, $medical->id);
    }

    private function storeDataUrl(?string $dataUrl, string $prefix): ?string
    {
        if (! $dataUrl || ! str_contains($dataUrl, 'base64,')) {
            return null;
        }

        $binary = base64_decode(explode('base64,', $dataUrl)[1], true);
        if ($binary === false) {
            return null;
        }

        $path = $prefix.uniqid().'.png';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /**
     * @param  array<int, UploadedFile>  $attachments
     * @return array<string, UploadedFile>
     */
    private function indexAttachments(array $attachments): array
    {
        $indexed = [];
        foreach ($attachments as $file) {
            if ($file instanceof UploadedFile) {
                $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $indexed[strtolower(trim($name))] = $file;
            }
        }

        return $indexed;
    }
}
