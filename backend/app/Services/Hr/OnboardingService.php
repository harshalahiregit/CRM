<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrCandidateNote;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrOnboarding;
use App\Models\Hr\HrOnboardingDocument;
use App\Notifications\WhatsApp\OnboardingWelcomeNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OnboardingService
{
    public const DOC_DISK      = 'hr_documents';
    public const ALLOWED_MIMES = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    public const MAX_SIZE_KB   = 10240; // 10 MB

    public function __construct(
        private EmployeeService $employeeService,
        private CandidateService $candidateService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $query = HrOnboarding::where('tenant_id', $tenantId)->with(['candidate.offer', 'documents']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['verification_status']) && $filters['verification_status'] !== 'All') {
            $query->where('verification_status', $filters['verification_status']);
        }

        return $query->latest()->get();
    }

    /* ─────────────────────────────────────────────────────────────────────
     | Candidate onboarding lifecycle (Sprint 2 / Req Doc 3)
     | Selected → Onboarding (before Offer) → Verify → Offer → Joined → Employee
     ───────────────────────────────────────────────────────────────────── */

    /**
     * Kick off onboarding for a just-selected candidate: create the record with
     * a portal token and send the congratulations Email + WhatsApp with the
     * onboarding link. Called automatically when the final interview passes.
     */
    public function startForCandidate(HrCandidate $candidate): HrOnboarding
    {
        // Reuse an existing onboarding record if one already exists for the candidate.
        $existing = HrOnboarding::where('candidate_id', $candidate->id)->first();
        if ($existing) {
            return $existing;
        }

        $candidate->loadMissing('jobPosting');

        $onboarding = HrOnboarding::create([
            'tenant_id'           => $candidate->tenant_id,
            'candidate_id'        => $candidate->id,
            'candidate_name'      => $candidate->name,
            'position'            => optional($candidate->jobPosting)->title,
            'department'          => optional($candidate->jobPosting)->department,
            'access_token'        => Str::random(48),
            'status'              => 'Pending',
            'verification_status' => 'Pending',
            'invited_at'          => now(),
        ]);

        // Selected → moves into the offer/onboarding phase (no new pipeline stage).
        $candidate->update(['final_decision' => 'Selected', 'stage' => 'Offer']);
        $candidate->recordAudit('Selected — Onboarding Started', null, null, ['onboarding_id' => $onboarding->id]);

        $link = $this->portalLink($onboarding);

        // Congratulations Email (best-effort).
        if ($candidate->email) {
            try {
                Mail::to($candidate->email)->send(new \App\Mail\OnboardingWelcomeMail($onboarding, $link));
            } catch (\Throwable $e) {
                Log::channel('hr')->error('Onboarding congrats email failed', ['onboarding_id' => $onboarding->id, 'error' => $e->getMessage()]);
            }
        }

        // Congratulations WhatsApp (best-effort; already guarded internally).
        try {
            OnboardingWelcomeNotification::send($onboarding);
        } catch (\Throwable $e) {
            Log::channel('hr')->error('Onboarding congrats WhatsApp failed', ['onboarding_id' => $onboarding->id, 'error' => $e->getMessage()]);
        }

        Log::channel('hr')->info('Onboarding started for selected candidate', ['onboarding_id' => $onboarding->id, 'tenant_id' => $candidate->tenant_id, 'candidate_id' => $candidate->id]);

        return $onboarding->load('candidate');
    }

    /** Resolve an onboarding by its public portal token. */
    public function byToken(string $token): HrOnboarding
    {
        $onboarding = HrOnboarding::where('access_token', $token)->with(['candidate', 'documents'])->first();

        if (! $onboarding) {
            throw new BusinessException('Onboarding link is invalid or has expired.', 404);
        }

        return $onboarding;
    }

    /** Portal-safe payload for the public candidate view (never leaks HR data). */
    public function publicView(HrOnboarding $onboarding): array
    {
        return [
            'candidate_name'      => $onboarding->candidate_name,
            'position'            => $onboarding->position,
            'department'          => $onboarding->department,
            'verification_status' => $onboarding->verification_status,
            'rejection_reason'    => $onboarding->verification_status === 'Rejected' ? $onboarding->rejection_reason : null,
            'submission'          => $onboarding->submission,
            'submitted_at'        => optional($onboarding->submitted_at)->toIso8601String(),
            'document_types'      => HrOnboarding::DOCUMENT_TYPES,
            'documents'           => $onboarding->documents->map(fn ($d) => [
                'id' => $d->id, 'type' => $d->type, 'original_name' => $d->original_name, 'size_kb' => $d->size_kb,
            ])->all(),
            'editable'            => in_array($onboarding->verification_status, ['Pending', 'Submitted', 'Rejected'], true),
        ];
    }

    /**
     * Full self-service portal payload (Sprint 2): dashboard, live progress
     * tracker, profile, documents+verification, tasks, notifications, timeline
     * and HR communication. Token-scoped and portal-safe — never leaks internal
     * HR identity or another candidate's data.
     */
    public function portalDashboard(HrOnboarding $onboarding): array
    {
        $onboarding->loadMissing(['documents', 'candidate.jobPosting', 'candidate.assignedRecruiter', 'candidate.offer', 'candidate.auditLogs']);
        $candidate = $onboarding->candidate;

        return [
            'candidate' => [
                'reference'          => $candidate ? 'CAND-'.str_pad((string) $candidate->id, 4, '0', STR_PAD_LEFT) : null,
                'name'               => $onboarding->candidate_name,
                'email'              => $candidate?->email,
                'applied_job'        => optional($candidate?->jobPosting)->title ?? $onboarding->position,
                'department'         => $onboarding->department,
                'experience_years'   => $candidate?->experience_years,
                'current_status'     => $this->currentStatusLabel($candidate, $onboarding),
                'assigned_recruiter' => optional($candidate?->assignedRecruiter)->name,
            ],
            'progress'            => $this->progressSteps($candidate, $onboarding),
            'verification_status' => $onboarding->verification_status,
            'rejection_reason'    => $onboarding->verification_status === 'Rejected' ? $onboarding->rejection_reason : null,
            'profile'             => [
                'submission'   => $onboarding->submission,
                'editable'     => in_array($onboarding->verification_status, ['Pending', 'Submitted', 'Rejected'], true),
                'submitted_at' => optional($onboarding->submitted_at)->toIso8601String(),
            ],
            'document_types'      => HrOnboarding::DOCUMENT_TYPES,
            'documents'           => $onboarding->documents->map(fn ($d) => [
                'id' => $d->id, 'type' => $d->type, 'original_name' => $d->original_name, 'size_kb' => $d->size_kb,
                'status' => $d->status ?? 'Pending', 'remarks' => $d->remarks,
            ])->all(),
            'tasks'               => $this->onboardingTasks($onboarding),
            'notifications'       => $this->candidateNotifications($candidate),
            'timeline'            => $this->candidateTimeline($candidate),
            'communication'       => $this->candidateMessages($candidate),
        ];
    }

    /** Recruitment progress tracker: Applied → … → Employee (auto-derived). */
    private function progressSteps(?HrCandidate $candidate, HrOnboarding $onboarding): array
    {
        $order = ['Applied', 'Screening', 'Assessment', 'Interview', 'Selected', 'Onboarding', 'Offer', 'Joining', 'Employee'];

        $hasInterview  = $candidate ? $candidate->interviewRounds()->exists() : false;
        $selected      = $candidate && ($candidate->final_decision === 'Selected' || $onboarding->exists);
        $offer         = $candidate?->offer;
        $offerAccepted = $offer && $offer->status === 'Accepted';
        $employee      = $candidate ? HrEmployee::where('candidate_id', $candidate->id)->exists() : false;
        $stageIdx      = array_search($candidate?->stage, ['Applied', 'Screening', 'Assessment', 'Interview', 'Offer', 'Hired', 'Rejected'], true);
        $stageIdx      = $stageIdx === false ? 0 : $stageIdx;

        $reached = [
            'Applied'    => true,
            'Screening'  => $stageIdx >= 1 || $hasInterview || $selected,
            'Assessment' => $stageIdx >= 2 || $hasInterview || $selected,
            'Interview'  => $hasInterview || $selected,
            'Selected'   => (bool) $selected,
            'Onboarding' => true, // an active portal means onboarding has begun
            'Offer'      => (bool) $offer,
            'Joining'    => (bool) ($offerAccepted || $onboarding->joining_confirmed_at),
            'Employee'   => $employee,
        ];

        $furthest = 0;
        foreach ($order as $i => $key) {
            if ($reached[$key]) {
                $furthest = $i;
            }
        }

        return array_map(function ($key, $i) use ($reached, $furthest) {
            $status = ! $reached[$key] ? 'pending' : ($i < $furthest ? 'done' : 'current');

            return ['key' => $key, 'label' => $key, 'status' => $status];
        }, $order, array_keys($order));
    }

    /** Candidate-facing status label for the dashboard. */
    private function currentStatusLabel(?HrCandidate $candidate, HrOnboarding $onboarding): string
    {
        if (! $candidate) {
            return 'In Progress';
        }
        if (HrEmployee::where('candidate_id', $candidate->id)->exists()) {
            return 'Hired · Employee Onboarded';
        }
        if ($offer = $candidate->offer) {
            return match ($offer->status) {
                'Accepted' => 'Offer Accepted',
                'Sent'     => 'Offer Received',
                'Rejected' => 'Offer Declined',
                default    => 'Offer '.$offer->status,
            };
        }
        if ($onboarding->isApproved()) {
            return 'Onboarding Approved · Awaiting Offer';
        }
        if ($onboarding->verification_status === 'Submitted') {
            return 'Onboarding Under Review';
        }
        if ($onboarding->verification_status === 'Rejected') {
            return 'Onboarding Needs Changes';
        }
        if ($candidate->final_decision === 'Selected') {
            return 'Selected · Onboarding In Progress';
        }

        return $candidate->stage;
    }

    /** My Tasks: onboarding checklist + completion percentage. */
    private function onboardingTasks(HrOnboarding $onboarding): array
    {
        $required = ['aadhaar', 'pan', 'resume', 'photo', 'address_proof'];
        $items    = [];
        $items[]  = ['key' => 'profile', 'label' => 'Complete personal, address & bank details', 'done' => ! empty($onboarding->submission)];

        foreach ($required as $type) {
            $doc = $onboarding->documents->firstWhere('type', $type);
            $items[] = [
                'key'   => $type,
                'label' => 'Upload '.ucwords(str_replace('_', ' ', $type)),
                'done'  => (bool) ($doc && $doc->status !== 'Rejected'),
            ];
        }

        $done = count(array_filter($items, fn ($t) => $t['done']));

        return [
            'items'     => $items,
            'completed' => $done,
            'total'     => count($items),
            'percent'   => (int) round($done / max(count($items), 1) * 100),
        ];
    }

    /** Candidate-facing notifications derived from their audit timeline. */
    private function candidateNotifications(?HrCandidate $candidate): array
    {
        if (! $candidate) {
            return [];
        }

        $map = [
            'Selected — Onboarding Started'     => ['success', 'Congratulations! You have been selected'],
            'Onboarding Submitted'              => ['info', 'Your onboarding details were submitted'],
            'Onboarding Approved — Offer Ready' => ['success', 'Onboarding verified & approved'],
            'Onboarding Rejected'               => ['warning', 'Your onboarding needs changes'],
            'Document Rejected'                 => ['warning', 'A document needs to be re-uploaded'],
            'Document Verified'                 => ['success', 'A document was verified'],
            'Offer Generated'                   => ['info', 'Your offer is being prepared'],
            'Offer Sent'                        => ['success', 'Your offer letter is ready'],
            'Offer Viewed'                      => ['info', 'You viewed your offer letter'],
            'Offer Accepted'                    => ['success', 'Offer accepted'],
            'Offer Declined'                    => ['warning', 'Offer declined'],
            'Offer Expired'                     => ['warning', 'Your offer has expired'],
            'Offer Regenerated'                 => ['info', 'A fresh offer has been issued'],
            'Joining Confirmed'                 => ['success', 'Your joining has been confirmed'],
            'Employee Created'                  => ['success', 'Welcome aboard — your employee record is ready'],
        ];

        $out = [];
        foreach ($candidate->auditLogs as $log) { // newest first (Auditable orders by latest id)
            foreach ($map as $needle => [$type, $title]) {
                if (str_starts_with($log->action, $needle)) {
                    $out[] = ['type' => $type, 'title' => $title, 'detail' => $log->comment, 'at' => optional($log->created_at)->toIso8601String()];
                    break;
                }
            }
        }

        return array_slice($out, 0, 25);
    }

    /** Portal-safe activity timeline (no internal HR identity leaked). */
    private function candidateTimeline(?HrCandidate $candidate): array
    {
        if (! $candidate) {
            return [];
        }

        return $candidate->auditLogs->map(fn ($l) => [
            'action'     => $l->action,
            'comment'    => $l->comment,
            'created_at' => optional($l->created_at)->toIso8601String(),
        ])->all();
    }

    /** HR → candidate communication (notes explicitly marked visible). */
    private function candidateMessages(?HrCandidate $candidate): array
    {
        if (! $candidate) {
            return [];
        }

        return HrCandidateNote::where('candidate_id', $candidate->id)
            ->where('visible_to_candidate', true)
            ->latest()->get()
            ->map(fn ($n) => ['from' => 'HR Team', 'body' => $n->body, 'at' => optional($n->created_at)->toIso8601String()])
            ->all();
    }

    /** HR verifies a single uploaded document (Verified / Rejected + remarks). */
    public function verifyDocument(HrOnboardingDocument $document, string $status, ?string $remarks): HrOnboardingDocument
    {
        $document->update(['status' => $status, 'remarks' => $remarks, 'verified' => $status === 'Verified']);

        $candidate = optional($document->onboarding)->candidate;
        optional($candidate)->recordAudit('Document '.$status.': '.ucwords(str_replace('_', ' ', $document->type)), null, $remarks);

        Log::channel('hr')->info('Onboarding document verified', ['document_id' => $document->id, 'tenant_id' => $document->tenant_id, 'status' => $status]);

        return $document;
    }

    /** Candidate submits their onboarding details (public, token-scoped). */
    public function submit(HrOnboarding $onboarding, array $submission): HrOnboarding
    {
        if ($onboarding->verification_status === 'Approved') {
            throw new BusinessException('Your onboarding has already been approved.', 422);
        }

        $onboarding->update([
            'submission'          => $submission,
            'verification_status' => 'Submitted',
            'submitted_at'        => now(),
            'rejection_reason'    => null,
        ]);

        $onboarding->recordAudit('Onboarding Submitted');
        optional($onboarding->candidate)->recordAudit('Onboarding Submitted');

        Log::channel('hr')->info('Onboarding submitted by candidate', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id]);

        return $onboarding->fresh(['documents']);
    }

    /**
     * Store a candidate-uploaded onboarding document. One document per type —
     * re-uploading (e.g. after HR rejects it) replaces the previous file and
     * resets the document's verification status to Pending.
     */
    public function storeDocument(HrOnboarding $onboarding, UploadedFile $file, string $type): HrOnboardingDocument
    {
        $type = in_array($type, HrOnboarding::DOCUMENT_TYPES, true) ? $type : 'other';

        foreach ($onboarding->documents()->where('type', $type)->get() as $old) {
            if ($old->path && Storage::disk(self::DOC_DISK)->exists($old->path)) {
                Storage::disk(self::DOC_DISK)->delete($old->path);
            }
            $old->delete();
        }

        $ext      = strtolower($file->getClientOriginalExtension());
        $safeName = Str::slug($onboarding->candidate_name).'_'.$type.'_'.$onboarding->id.'_'.time().'.'.$ext;
        $dir      = 'onboarding/tenant_'.$onboarding->tenant_id.'/'.$onboarding->id;

        $path = $file->storeAs($dir, $safeName, self::DOC_DISK);

        $doc = $onboarding->documents()->create([
            'tenant_id'     => $onboarding->tenant_id,
            'type'          => $type,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size_kb'       => (int) round($file->getSize() / 1024),
            'mime'          => $file->getClientMimeType(),
            'status'        => 'Pending',
        ]);

        optional($onboarding->candidate)->recordAudit('Document uploaded: '.ucwords(str_replace('_', ' ', $type)));

        return $doc;
    }

    /**
     * HR runs document / background / medical verification and approves or
     * rejects. Approval unlocks offer generation for the candidate.
     */
    public function verify(HrOnboarding $onboarding, array $input): HrOnboarding
    {
        $data = array_filter([
            'doc_verified'        => $input['doc_verified'] ?? null,
            'background_verified' => $input['background_verified'] ?? null,
            'medical_verified'    => $input['medical_verified'] ?? null,
            'verification_notes'  => $input['verification_notes'] ?? null,
        ], fn ($v) => $v !== null);

        $decision = $input['decision'] ?? null; // approve | reject

        if ($decision === 'approve') {
            $data['verification_status'] = 'Approved';
            $data['verified_at']         = now();
            $data['rejection_reason']    = null;
        } elseif ($decision === 'reject') {
            $data['verification_status'] = 'Rejected';
            $data['rejection_reason']    = $input['rejection_reason'] ?? 'Documents did not pass verification.';
        }

        $onboarding->update($data);

        if ($decision === 'approve') {
            $onboarding->recordAudit('Onboarding Approved');
            optional($onboarding->candidate)->recordAudit('Onboarding Approved — Offer Ready');
        } elseif ($decision === 'reject') {
            $onboarding->recordAudit('Onboarding Rejected', null, $data['rejection_reason']);
            optional($onboarding->candidate)->recordAudit('Onboarding Rejected', null, $data['rejection_reason']);
        }

        Log::channel('hr')->info('Onboarding verification updated', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id, 'decision' => $decision]);

        return $onboarding->fresh(['candidate', 'documents']);
    }

    /**
     * Confirm joining after the offer is accepted: auto-create the Employee (reuse
     * EmployeeService), complete the onboarding, and move the candidate to Hired.
     * Idempotent — never creates a duplicate employee.
     */
    public function confirmJoining(HrOnboarding $onboarding): HrEmployee
    {
        $candidate = $onboarding->candidate;
        $offer     = $candidate?->offer;

        $employee = HrEmployee::where('candidate_id', $onboarding->candidate_id)->first();

        $manager = $onboarding->reporting_manager_name ?? optional(optional($candidate)->assignedRecruiter)->name;

        if (! $employee) {
            $employee = $this->employeeService->create([
                'candidate_id'           => $onboarding->candidate_id,
                'onboarding_id'          => $onboarding->id,
                'name'                   => $onboarding->candidate_name,
                'email'                  => $candidate?->email,
                'phone'                  => $candidate?->phone,
                'department'             => $onboarding->department ?? optional($offer)->department,
                'designation'            => $onboarding->position ?? optional($offer)->position,
                'reporting_manager_name' => $manager,
                'joining_date'           => $onboarding->joining_date ?? optional($offer)->joining_date,
                'status'                 => 'Active',
            ], $onboarding->tenant_id);
        }

        // Auto-complete every remaining onboarding step — no manual ticking.
        $onboarding->update([
            'status'                  => 'Completed',
            'employee_code'           => $employee->employee_code,
            'reporting_manager_name'  => $onboarding->reporting_manager_name ?? $manager,
            'joining_confirmed_at'    => now(),
            'step_doc_verification'   => true,
            'step_joining_confirmed'  => true,
            'step_emp_id_generated'   => true,
            'step_dept_assigned'      => true,
            'step_manager_assigned'   => true,
            'step_record_created'     => true,
        ]);

        // Candidate → Hired + Selected (+ audit) via the existing decision flow.
        if ($candidate) {
            $this->candidateService->updateDecision($candidate, 'Selected');
            $candidate->recordAudit('Joining Confirmed', null, null, ['employee_code' => $employee->employee_code]);
            $candidate->recordAudit('Employee Created: '.$employee->employee_code);
        }

        Log::channel('hr')->info('Joining confirmed, employee created', ['onboarding_id' => $onboarding->id, 'employee_id' => $employee->id, 'tenant_id' => $onboarding->tenant_id]);

        return $employee;
    }

    private function portalLink(HrOnboarding $onboarding): string
    {
        return rtrim(config('hr_publishing.onboarding_portal_url'), '/').'/'.$onboarding->access_token;
    }

    /* ─────────────────────────────────────────────────────────────────────
     | Legacy HR-side onboarding checklist (kept intact for backward compat)
     ───────────────────────────────────────────────────────────────────── */

    public function create(array $data, int $tenantId): HrOnboarding
    {
        $data['tenant_id'] = $tenantId;
        $record = HrOnboarding::create([...$data, 'status' => 'Pending']);

        if (! empty($data['candidate_id'])) {
            $candidate = HrCandidate::find($data['candidate_id']);
            if ($candidate && $candidate->email) {
                Mail::to($candidate->email)->send(
                    new \App\Mail\OnboardingWelcomeMail($record)
                );
            }
        }

        Log::channel('hr')->info('Onboarding record created', ['onboarding_id' => $record->id, 'tenant_id' => $tenantId]);

        return $record;
    }

    public function toggleChecklist(HrOnboarding $onboarding, array $checklist): HrOnboarding
    {
        $onboarding->update(['document_checklist' => $checklist]);

        Log::channel('hr')->info('Onboarding checklist updated', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id]);

        return $onboarding->fresh();
    }

    public function toggleStep(HrOnboarding $onboarding, string $step): HrOnboarding
    {
        $col = 'step_'.$step;
        $onboarding->update([$col => ! $onboarding->$col]);

        $steps = [
            $onboarding->step_doc_verification,
            $onboarding->step_joining_confirmed,
            $onboarding->step_emp_id_generated,
            $onboarding->step_dept_assigned,
            $onboarding->step_manager_assigned,
            $onboarding->step_record_created,
        ];
        $doneCount = count(array_filter($steps));
        $status = $doneCount === 0 ? 'Pending' : ($doneCount === 6 ? 'Completed' : 'In Progress');
        $onboarding->update(['status' => $status]);

        if ($status === 'Completed' && ! HrEmployee::where('candidate_id', $onboarding->candidate_id)->exists()) {
            $employee = $this->employeeService->create([
                'candidate_id'           => $onboarding->candidate_id,
                'onboarding_id'          => $onboarding->id,
                'name'                   => $onboarding->candidate_name,
                'department'             => $onboarding->department,
                'designation'            => $onboarding->position,
                'reporting_manager_name' => $onboarding->reporting_manager_name,
                'joining_date'           => $onboarding->joining_date,
                'status'                 => 'Active',
            ], $onboarding->tenant_id);

            Log::channel('hr')->info('Employee auto-created from completed onboarding', ['onboarding_id' => $onboarding->id, 'employee_id' => $employee->id, 'tenant_id' => $onboarding->tenant_id]);
        }

        Log::channel('hr')->info('Onboarding step toggled', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id, 'step' => $step, 'status' => $status]);

        return $onboarding->fresh();
    }

    public function destroy(HrOnboarding $onboarding): void
    {
        $onboarding->delete();

        Log::channel('hr')->info('Onboarding record deleted', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id]);
    }
}
