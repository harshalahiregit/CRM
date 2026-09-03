<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvMedicalBulkBatch;
use App\Models\Tpv\TpvMedicalMessage;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\Tpv\TpvWorkPackage;
use App\Models\User;
use App\Services\Medical\MedicalGeoResolver;
use App\Services\NotificationService as Bell;
use App\Services\Notifications\NotificationService as Channels;
use App\Support\FrontendUrl;
use App\Support\Medical\HealthScore;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Spreadsheet;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use App\Support\Tpv\TpvSettings;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The medical lifecycle on the TPV side: examine → quality check → clearance,
 * with a re-examination whenever the answer was no.
 *
 * Everything about a certificate that is a DECISION lives here — who may issue
 * one, what makes it clearance, how many times it may go back and forth, and
 * when the requirement does not apply at all. The worker service still owns the
 * five-step registration wizard and delegates its medical step to this.
 *
 * The Purchase side has a mirror of this class. They are deliberately separate:
 * two registers, two sets of tables, two vendor populations — the shared parts
 * are the vocabulary (App\Support\Medical) and the certificate rendering, not
 * the workflow's data access.
 */
class TpvMedicalWorkflowService
{
    /** Column prefix on the timeline table for this side. */
    private const WORKER_KEY = 'tpv_worker_id';

    public function __construct(
        private TpvSettings $settings,
        private MedicalGeoResolver $geo,
        private Bell $bell,
        private Channels $channels,
    ) {}

    /* ── Configuration ──────────────────────────────────────────────────── */

    /** The effective medical settings for a tenant. */
    public function config(?int $tenantId): array
    {
        return $this->settings->medical($tenantId);
    }

    /* ── Recording an examination ───────────────────────────────────────── */

    /**
     * Record an examination — internal (a doctor filled the form) or external
     * (a certificate someone else's doctor signed).
     *
     * Re-tests accumulate rather than overwrite. A same-day save updates the
     * record in place ONLY while it is still awaiting review; once a reviewer
     * has ruled on it, or the caller says this is a re-examination, the new exam
     * becomes the next attempt of that day so the decided record survives
     * exactly as it was decided.
     *
     * @param  array<string,mixed>  $data
     */
    public function record(TpvWorker $worker, array $data, User $actor, string $origin = MedicalWorkflow::ORIGIN_ADMIN, ?TpvWorkerMedical $previous = null): TpvWorkerMedical
    {
        $config = $this->config($worker->tenant_id);
        $isReexam = (bool) ($data['is_reexam'] ?? false) || $previous !== null;

        $values = $this->prepare($worker, $data, $actor, $origin, $config);

        // Which record are we writing? The same-day one if it is still open,
        // otherwise a fresh attempt.
        $existing = $worker->medicalHistory()
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
                        ?? $worker->medicalHistory()->orderByDesc('exam_date')->orderByDesc('attempt_no')->first()?->id;
                }
                $medical = $worker->medicalHistory()->create($values);
            }

            // The number is derived from the row id, so it is unique without a
            // sequence table and cannot collide under concurrency.
            if (blank($medical->certificate_no)) {
                $medical->certificate_no = $this->certificateNumber($medical);
                $medical->save();
            }

            // An internal examination can be trusted straight through where the
            // tenant has said so; everything external always faces a reviewer.
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

        $worker->update(['current_step' => max((int) $worker->current_step, 2)]);
        // The caller still holds this instance and asks it about its medical
        // next (the induction gate does); drop the stale cached relations.
        $worker->unsetRelation('medical')->unsetRelation('medicalHistory');

        $this->log($medical, $worker, $isReexam ? MedicalWorkflow::ACTION_REEXAMINED : MedicalWorkflow::ACTION_SUBMITTED, $actor, [
            'body' => $isReexam
                ? 'Re-examination recorded'.($medical->fitness_label ? ' — outcome '.$medical->fitness_label : '').'.'
                : 'Examination recorded'.($medical->fitness_label ? ' — outcome '.$medical->fitness_label : '').'.',
        ]);

        $worker->recordAudit($isReexam ? 'Medical Re-examined' : 'Medical Recorded', $actor, null, [
            'fitness' => $medical->fitness_status, 'certificate_no' => $medical->certificate_no,
        ]);

        if ($medical->qc_status === MedicalQcStatus::PENDING) {
            $this->notifyReviewers($medical, $worker, $actor);
        }

        Log::channel('tpv')->info('TPV medical recorded', [
            'worker_id' => $worker->id, 'medical_id' => $medical->id,
            'origin' => $origin, 'qc' => $medical->qc_status, 'tenant_id' => $worker->tenant_id,
        ]);

        return $medical->fresh();
    }

    /**
     * Turn a submitted payload into column values: defaults applied, files
     * stored, score computed, and everything a client must not be trusted with
     * (the IP, the doctor's licence, the storage paths) taken from the server.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function prepare(TpvWorker $worker, array $data, User $actor, string $origin, array $config): array
    {
        // Paths are never accepted from a caller — a client naming its own
        // storage path could point a record at somebody else's file.
        unset($data['signature_path'], $data['capture_photo_path'], $data['pdf_path'],
              $data['certificate_no'], $data['qc_status'], $data['qc_by'], $data['qc_at'],
              $data['iteration_count'], $data['system_ip'], $data['geo_place']);

        $data['exam_date'] ??= now()->toDateString();
        $data['exam_date'] = Carbon::parse($data['exam_date'])->toDateString();

        // A certificate that never expires is not a certificate. Where the
        // examiner did not stamp one, derive it from the configured currency.
        if (empty($data['valid_until'])) {
            $data['valid_until'] = Carbon::parse($data['exam_date'])
                ->copy()->addMonths((int) ($config['validity_months'] ?? 12))->toDateString();
        }

        $data['screening_band'] = Fitness::bandForScore(
            isset($data['screening_score']) ? (int) $data['screening_score'] : null
        );

        // Evidence files. The uploaded report is what an external certificate
        // IS, so losing it would empty the record of its meaning.
        if (($data['report_file'] ?? null) instanceof UploadedFile) {
            $data['document_path'] = $data['report_file']->store(
                'tpv/medical/'.$worker->tenant_id.'/'.$worker->id, 'local'
            );
        }
        unset($data['report_file']);

        foreach ([
            'signature_data' => ['signature_path', 'workers/signatures/sig_'],
            'capture_photo'  => ['capture_photo_path', 'workers/medical/photos/photo_'],
        ] as $src => [$column, $prefix]) {
            $decoded = $this->storeDataUrl($data[$src] ?? null, $prefix);
            if ($decoded) {
                $data[$column] = $decoded;
            }
            unset($data[$src]);
        }

        // Legal capture — the address is resolved from the coordinates so the
        // certificate can name a place, and the IP is the server's observation,
        // never the client's claim.
        $data['system_ip'] = request()?->ip();
        if (! empty($data['geo_location'])) {
            $data['geo_place'] = $this->geo->resolve($data['geo_location']);
        }

        // The examining doctor's credentials are snapshotted onto the record: a
        // certificate must keep saying what it said when it was signed, even if
        // the doctor's profile is edited afterwards.
        if ($origin === MedicalWorkflow::ORIGIN_DOCTOR_PORTAL) {
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

        // Health score: computed from the examination unless the doctor stated
        // one, in which case the record says it was stated rather than measured.
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
            'tenant_id'   => $worker->tenant_id,
            'recorded_by' => $actor->id,
            'origin'      => $origin,
            'qc_status'   => MedicalQcStatus::PENDING,
        ];
    }

    /* ── Quality check ──────────────────────────────────────────────────── */

    /**
     * Approve, reject or hold a certificate.
     *
     * A rejection is terminal — the worker failed the medical, and the remedy is
     * a re-examination, not another look at the same paper. A hold goes back to
     * whoever submitted it and can come again. Both demand a reason: a refusal
     * nobody can act on is not a review.
     *
     * @param  array{reason_code?:string|null, note?:string|null}  $opts
     */
    public function decide(TpvWorkerMedical $medical, string $decision, array $opts, User $actor): TpvWorkerMedical
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
        $this->assertReviewer($actor, $medical->tenant_id);

        $worker = $medical->worker;

        $medical->forceFill([
            'qc_status'      => $decision,
            'qc_by'          => $actor->id,
            'qc_at'          => now(),
            'qc_reason_code' => $opts['reason_code'] ?? null,
            'qc_note'        => $opts['note'] ?? null,
            // Approval is also the §16 sign-off — the reviewer is the person who
            // stands behind the verdict, so the existing sign-off columns record
            // them rather than being left for a second, separate action.
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

        Log::channel('tpv')->info('TPV medical quality check', [
            'medical_id' => $medical->id, 'decision' => $decision,
            'by' => $actor->id, 'tenant_id' => $medical->tenant_id,
        ]);

        return $medical->fresh();
    }

    /**
     * The vendor or doctor answers a hold and sends the certificate back.
     *
     * This is the round-counter: the configured maximum (10) is a hard stop, not
     * a display depth. At the cap the conversation ends and the reviewer has to
     * decide on what they have — which is the point, because a certificate that
     * has been argued about eleven times is not going to be resolved by a
     * twelfth attempt.
     *
     * @param  array<string,mixed>  $data
     */
    public function resubmit(TpvWorkerMedical $medical, array $data, User $actor): TpvWorkerMedical
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

        // A resubmission usually carries a replacement document.
        if (($data['report_file'] ?? null) instanceof UploadedFile) {
            $update['document_path'] = $data['report_file']->store(
                'tpv/medical/'.$medical->tenant_id.'/'.$medical->tpv_worker_id, 'local'
            );
        }
        foreach (['valid_until', 'exam_date', 'fitness_status', 'examiner_name', 'clinic_name', 'doctor_license_no', 'restrictions'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $update[$field] = $data[$field];
            }
        }

        $medical->forceFill($update)->save();

        $this->log($medical, $medical->worker, MedicalWorkflow::ACTION_RESUBMITTED, $actor, [
            'body' => $data['message'] ?? 'Certificate resubmitted for review.',
        ]);

        $this->notifyReviewers($medical->fresh(), $medical->worker, $actor);

        return $medical->fresh();
    }

    /** A remark on the current round — does not advance the exchange counter. */
    public function comment(TpvWorkerMedical $medical, string $body, ?UploadedFile $file, User $actor): TpvMedicalMessage
    {
        $attachment = $file?->store('tpv/medical/'.$medical->tenant_id.'/'.$medical->tpv_worker_id, 'local');

        $message = $this->log($medical, $medical->worker, MedicalWorkflow::ACTION_COMMENT, $actor, [
            'body'            => $body,
            'attachment_path' => $attachment,
            'attachment_name' => $file?->getClientOriginalName(),
        ]);

        // A comment is addressed to the other side of the conversation.
        if (MedicalWorkflow::sideForRole($actor->role) === MedicalWorkflow::SIDE_QUALITY) {
            $this->notifySubmitter($medical, $medical->worker, $actor, null, ['note' => $body]);
        } else {
            $this->notifyReviewers($medical, $medical->worker, $actor);
        }

        return $message;
    }

    /* ── History and clearance ──────────────────────────────────────────── */

    /**
     * A worker's medical history for their profile: every examination, the
     * current score, and how the score has moved.
     */
    public function history(TpvWorker $worker): array
    {
        $records = $worker->medicalHistory()
            ->with(['doctor:id,name', 'reviewer:id,name'])
            ->orderByDesc('exam_date')->orderByDesc('attempt_no')
            ->get();

        $latest = $records->first();
        $scores = $records->pluck('health_score')->filter()->values();

        return [
            'records'       => $records,
            'latest'        => $latest,
            'health_score'  => $latest?->health_score,
            'health_band'   => HealthScore::band($latest?->health_score),
            'score_scale'   => HealthScore::MAX,
            // Movement against the previous examination — the number a profile
            // actually needs ("improving" vs "a good score once, years ago").
            'score_trend'   => $scores->count() >= 2 ? round((float) $scores[0] - (float) $scores[1], 1) : null,
            'exam_count'    => $records->count(),
            'clearance'     => $this->clearanceFor($worker),
        ];
    }

    /**
     * Is medical a prerequisite for this worker, and is it satisfied?
     *
     * One answer used everywhere the question is asked — the induction block,
     * the dashboards, work authorization — so the gate and the message a user
     * reads can never disagree.
     *
     * @return array{required:bool, cleared:bool, bypassed:bool, status:string, message:string, medical_id:int|null}
     */
    public function clearanceFor(TpvWorker $worker): array
    {
        $config = $this->config($worker->tenant_id);

        if ($this->isBypassed($worker, $config)) {
            return [
                'required' => false, 'cleared' => true, 'bypassed' => true,
                'status' => 'not_applicable',
                'message' => 'Medical is not applicable for this project.',
                'medical_id' => null,
            ];
        }

        $medical = $worker->relationLoaded('medical')
            ? $worker->medical
            : $worker->medical()->first();
        $pending = (string) ($config['pending_message'] ?? 'Medical Report is Pending');

        [$status, $message] = match (true) {
            ! $medical                                        => ['missing',  $pending.' — no examination has been recorded.'],
            $medical->qc_status === MedicalQcStatus::REJECTED => ['rejected', 'Medical was rejected by the quality team. A re-examination is required.'],
            $medical->qc_status === MedicalQcStatus::HOLD     => ['hold',     $pending.' — the quality team has queried the certificate.'],
            $medical->qc_status === MedicalQcStatus::PENDING  => ['pending',  $pending.' — awaiting quality check.'],
            ! $medical->isPassing()                           => ['unfit',    'The medical outcome is not Fit.'],
            $medical->isExpired()                             => ['expired',  'The medical certificate has expired.'],
            default                                           => ['approved', 'Medical clearance is complete.'],
        };

        return [
            'required'   => true,
            'cleared'    => $status === 'approved',
            'bypassed'   => false,
            'status'     => $status,
            'message'    => $message,
            'medical_id' => $medical?->id,
        ];
    }

    /**
     * The admin bypass: "Not Applicable for the Project".
     *
     * Read in order of specificity — the work package the worker is assigned to
     * decides, and only where it says nothing does the tenant default apply. The
     * legacy per-worker skip (medical_status = 2) is still honoured so sites
     * that already opted out do not suddenly start blocking.
     */
    public function isBypassed(TpvWorker $worker, ?array $config = null): bool
    {
        if ((int) ($worker->medical_status ?? 0) === 2) {
            return true;
        }

        if ($worker->work_package_id) {
            $flag = TpvWorkPackage::whereKey($worker->work_package_id)->value('medical_not_applicable');
            if ($flag !== null) {
                return (bool) $flag;
            }
        }

        $config ??= $this->config($worker->tenant_id);

        return (bool) ($config['not_applicable_default'] ?? false);
    }

    /* ── Bulk external certificates ─────────────────────────────────────── */

    /**
     * The columns of the bulk template. The first row is the header the import
     * matches on, so the template a vendor downloads and the file they send back
     * are guaranteed to agree.
     *
     * @return array{headers: list<string>, sample: list<string>, notes: list<string>}
     */
    public static function template(): array
    {
        return [
            'headers' => [
                'worker_code', 'worker_name', 'exam_date', 'valid_until', 'fitness_status',
                'doctor_name', 'doctor_license_no', 'clinic_name', 'blood_group',
                'height_cm', 'weight_kg', 'bp_systolic', 'bp_diastolic', 'restrictions', 'remarks',
            ],
            'sample' => [
                'WRK-0001', 'Ramesh Kumar', '2026-12-01', '2027-11-30', 'Fit',
                'Dr A. Sharma', 'MH-123456', 'City Clinic', 'B+',
                '172', '68', '120', '80', '', 'Annual medical',
            ],
            'notes' => [
                'worker_code must match a worker already registered under your vendor.',
                'fitness_status: '.implode(' / ', Fitness::ALL),
                'Dates are YYYY-MM-DD. Leave valid_until blank to default to one year after the exam.',
                'worker_name is for your reference only — the worker code is what the import matches on.',
                'Attach the certificate files alongside the sheet, each named after the worker code (WRK-0001.pdf).',
            ],
        ];
    }

    /**
     * Import a sheet of external certificates.
     *
     * Rows are independent: one bad row is reported and skipped rather than
     * failing the file, because a vendor uploading forty certificates should not
     * lose thirty-nine to one typo. Every reject carries its row number and the
     * reason, which is what the batch record is for.
     *
     * @param  array<int, UploadedFile>  $attachments  certificate files, matched by worker code
     */
    public function bulkImport(UploadedFile $sheet, ?int $vendorId, User $actor, string $side, array $attachments = []): TpvMedicalBulkBatch
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
            $lineNo = $i + 2; // header is row 1
            $get = fn (string $col) => isset($index[$col]) ? trim((string) ($row[$index[$col]] ?? '')) : '';

            $code = $get('worker_code');
            if ($code === '') {
                continue; // a blank trailing line, not an error worth reporting
            }

            try {
                $worker = TpvWorker::forTenant($actor->tenant_id)
                    ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
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

        return TpvMedicalBulkBatch::create([
            'tenant_id'     => $actor->tenant_id,
            'vendor_id'     => $vendorId,
            'uploaded_by'   => $actor->id,
            'uploaded_side' => $side,
            'file_name'     => $sheet->getClientOriginalName(),
            'file_path'     => $sheet->store('tpv/medical/bulk/'.$actor->tenant_id, 'local'),
            'total_rows'    => $created + count($errors),
            'created_count' => $created,
            'failed_count'  => count($errors),
            'errors'        => $errors,
        ]);
    }

    /* ── Timeline ───────────────────────────────────────────────────────── */

    /** The certificate's complete communication history, with the cap state. */
    public function timeline(TpvWorkerMedical $medical): array
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

    /**
     * Write one timeline entry. Called for every state change, so the history is
     * complete by construction rather than by remembering to log.
     */
    private function log(TpvWorkerMedical $medical, ?TpvWorker $worker, string $action, ?User $actor, array $extra = []): TpvMedicalMessage
    {
        return TpvMedicalMessage::create([
            'tenant_id'        => $medical->tenant_id,
            'medical_id'       => $medical->id,
            self::WORKER_KEY   => $medical->tpv_worker_id,
            'iteration_no'     => max(1, (int) $medical->iteration_count + 1),
            'action'           => $action,
            'author_side'      => MedicalWorkflow::sideForRole($actor?->role),
            'author_id'        => $actor?->id,
            'author_name'      => $actor?->name,
            'reason_code'      => $extra['reason_code'] ?? null,
            'body'             => $extra['body'] ?? null,
            'attachment_path'  => $extra['attachment_path'] ?? null,
            'attachment_name'  => $extra['attachment_name'] ?? null,
        ]);
    }

    /* ── People ─────────────────────────────────────────────────────────── */

    /**
     * The reviewers a tenant has nominated, falling back to its admins so a
     * certificate is never submitted into an empty room.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function reviewers(int $tenantId)
    {
        $ids = array_filter((array) ($this->config($tenantId)['qc_approver_ids'] ?? []));

        $query = User::query()->where('tenant_id', $tenantId)->where('status', 'active');

        return $ids
            ? $query->whereIn('id', $ids)->get()
            : $query->where('role', 'admin')->get();
    }

    /** Only a nominated reviewer (or an admin) may rule on a certificate. */
    private function assertReviewer(User $actor, int $tenantId): void
    {
        $ids = array_filter((array) ($this->config($tenantId)['qc_approver_ids'] ?? []));

        if ($ids && ! in_array($actor->id, $ids, true) && $actor->role !== 'admin') {
            throw new BusinessException('You are not assigned to quality-check medical certificates.');
        }
    }

    /* ── Notifications ──────────────────────────────────────────────────── */

    private function notifyReviewers(TpvWorkerMedical $medical, ?TpvWorker $worker, User $actor): void
    {
        $link = '/app/tpv/medical?certificate='.$medical->certificate_no;
        $title = 'Medical certificate awaiting quality check';
        $body  = trim(($worker?->name ?? 'A worker').' — '.($medical->certificate_no ?? '').' ('.$medical->origin_label.')');

        foreach ($this->reviewers($medical->tenant_id) as $reviewer) {
            $this->bell->notify($reviewer->id, $medical->tenant_id, 'tpv_medical_review', $title, $body, $link, $actor->id);
        }
    }

    /**
     * Tell the vendor — and the examining doctor, when there was one — what the
     * quality team decided. The vendor hears it in their portal and by e-mail,
     * because a held certificate that nobody reads is a worker who never
     * reaches site.
     */
    private function notifySubmitter(TpvWorkerMedical $medical, ?TpvWorker $worker, User $actor, ?string $decision, array $opts): void
    {
        $vendor = $worker?->vendor;
        $title  = $decision
            ? 'Medical certificate '.strtolower(MedicalQcStatus::label($decision))
            : 'New comment on a medical certificate';
        $body = trim(($worker?->name ?? 'Worker').' — '.($medical->certificate_no ?? '').
            (blank($opts['note'] ?? null) ? '' : ': '.$opts['note']));

        if ($medical->doctor_user_id) {
            $this->bell->notify($medical->doctor_user_id, $medical->tenant_id, 'tpv_medical_decision',
                $title, $body, '/doctor/examinations/'.$medical->id, $actor->id);
        }

        if ($vendor?->user_id) {
            $this->bell->notify($vendor->user_id, $medical->tenant_id, 'tpv_medical_decision',
                $title, $body, '/vendor-portal/medical', $actor->id);
        }

        if ($vendor?->email && $decision !== null) {
            $this->channels->emailHtml(
                $vendor->email,
                $title,
                view('emails.medical.decision', [
                    'title'    => $title,
                    'worker'   => $worker?->name,
                    'medical'  => $medical,
                    'decision' => $decision,
                    'note'     => $opts['note'] ?? null,
                    'reason'   => $opts['reason_code'] ?? null,
                    'portalUrl' => FrontendUrl::to('/vendor-portal/medical'),
                ])->render(),
                ['medical_id' => $medical->id, 'event' => 'medical_'.strtolower($decision)],
                null,
                $medical->tenant_id,
            );
        }
    }

    /* ── Small helpers ──────────────────────────────────────────────────── */

    /** MED-TPV-2026-000123 — readable, sortable, and unique by construction. */
    private function certificateNumber(TpvWorkerMedical $medical): string
    {
        $prefix = (string) config('medical.certificate.prefix', 'MED');

        return sprintf('%s-TPV-%d-%06d', $prefix, Carbon::parse($medical->exam_date)->year, $medical->id);
    }

    /** Decode a base64 data URL to a stored file, returning its path. */
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
     * Index uploaded certificate files by the worker code in their filename, so
     * a sheet row can find its own attachment.
     *
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
