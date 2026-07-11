<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
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
        $query = HrOnboarding::where('tenant_id', $tenantId)->with(['candidate', 'documents']);

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

    /** Store a candidate-uploaded onboarding document. */
    public function storeDocument(HrOnboarding $onboarding, UploadedFile $file, string $type): HrOnboardingDocument
    {
        $type = in_array($type, HrOnboarding::DOCUMENT_TYPES, true) ? $type : 'other';

        $ext      = strtolower($file->getClientOriginalExtension());
        $safeName = Str::slug($onboarding->candidate_name).'_'.$type.'_'.$onboarding->id.'_'.time().'.'.$ext;
        $dir      = 'onboarding/tenant_'.$onboarding->tenant_id.'/'.$onboarding->id;

        $path = $file->storeAs($dir, $safeName, self::DOC_DISK);

        return $onboarding->documents()->create([
            'tenant_id'     => $onboarding->tenant_id,
            'type'          => $type,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size_kb'       => (int) round($file->getSize() / 1024),
            'mime'          => $file->getClientMimeType(),
        ]);
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

        if (! $employee) {
            $employee = $this->employeeService->create([
                'candidate_id'           => $onboarding->candidate_id,
                'onboarding_id'          => $onboarding->id,
                'name'                   => $onboarding->candidate_name,
                'email'                  => $candidate?->email,
                'phone'                  => $candidate?->phone,
                'department'             => $onboarding->department ?? optional($offer)->department,
                'designation'            => $onboarding->position ?? optional($offer)->position,
                'reporting_manager_name' => $onboarding->reporting_manager_name,
                'joining_date'           => $onboarding->joining_date ?? optional($offer)->joining_date,
                'status'                 => 'Active',
            ], $onboarding->tenant_id);
        }

        $onboarding->update([
            'status'                => 'Completed',
            'employee_code'         => $employee->employee_code,
            'joining_confirmed_at'  => now(),
            'step_doc_verification' => true,
            'step_joining_confirmed'=> true,
            'step_emp_id_generated' => true,
            'step_record_created'   => true,
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
