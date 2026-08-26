<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorDocumentService;
use App\Support\Tpv\TpvOnboardingStatus as Status;
use App\Support\Vendor\VendorStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class TpvOnboardingService
{
    public function __construct(
        private VendorDocumentService $documentService,
        private RegistrationNumberService $registrationNumbers,
        private \App\Services\Notifications\NotificationService $channels,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $query = TpvOnboarding::forTenant($tenantId)
            ->with(['vendor:id,vendor_code,company_name,vendor_type,status']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        // Surface the tracker's "Blocking Reason" per row (why it isn't active yet).
        // Eager-load the documents used by outstandingDocuments() so awaiting-decision
        // rows don't each fire their own query.
        return $query->with(['vendor.documents:id,vendor_id,type,status'])->latest()->get()
            ->each(fn (TpvOnboarding $o) => $o->setAttribute('blocking_reason', $o->blockingReason()));
    }

    /** Start the 6-step wizard for a vendor. One onboarding per vendor. */
    public function create(array $data, int $tenantId): TpvOnboarding
    {
        $vendor = Vendor::forTenant($tenantId)->find($data['vendor_id']);

        if (! $vendor) {
            throw new BusinessException('Vendor not found.', 404);
        }
        $existing = TpvOnboarding::forTenant($tenantId)->where('vendor_id', $vendor->id)->first();
        if ($existing) {
            return $existing->fresh(['vendor']);
        }

        $onboarding = TpvOnboarding::create([
            ...$data,
            'tenant_id'    => $tenantId,
            'current_step' => 1,
            'status'       => Status::IN_PROGRESS,
        ]);

        // Mark the vendor as engaged for TPV without disturbing an existing
        // purchase engagement — one vendor can be both.
        $vendor->update(['engagements' => array_values(array_unique([...($vendor->engagements ?? []), 'tpv']))]);

        $onboarding->recordAudit('TPV Onboarding Started', null, null, ['vendor_code' => $vendor->vendor_code]);

        Log::channel('tpv')->info('TPV onboarding started', [
            'onboarding_id' => $onboarding->id, 'vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        // fresh(), not load() — see PurchaseRequestService::create().
        return $onboarding->fresh(['vendor']);
    }

    /** Advance/rewind the wizard pointer. */
    public function setStep(TpvOnboarding $onboarding, int $step, User $actor): TpvOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }
        if ($step < 1 || $step > Status::TOTAL_STEPS) {
            throw new BusinessException('Step must be between 1 and '.Status::TOTAL_STEPS.'.');
        }

        // A vendor may move forward only into the first step that is not yet done;
        // going back to re-read a finished step is always allowed. Enforced here
        // rather than in the wizard because the endpoint is the boundary — the UI
        // hiding a button is not a rule.
        //
        // Staff and admins are deliberately exempt: step 3 only counts as complete
        // once EVERY required document is uploaded, so gating them would stop a
        // reviewer opening step 4 to review the documents that have arrived.
        if ($this->vendorDriven($actor)) {
            $furthest = $this->furthestReachableStep($onboarding);

            if ($step > $furthest) {
                throw new BusinessException(
                    'Complete step '.$furthest.' before moving on.',
                    422
                );
            }
        }

        $onboarding->update(['current_step' => $step]);
        $onboarding->recordAudit('Onboarding Step Changed', $actor, null, ['step' => $step]);

        return $onboarding;
    }

    /**
     * Is this onboarding's vendor the one entitled to sign the minutes?
     *
     * The pivot's is_primary row is the designated signatory; meetings written
     * before that table existed fall back to kickoffable_id, which was the only
     * vendor a meeting could have.
     */
    private function isDesignatedSignatory(KickoffMeeting $meeting, TpvOnboarding $onboarding): bool
    {
        $vendorId = (int) $onboarding->vendor_id;

        $primary = $meeting->subjects()->where('is_primary', true)->first();

        if ($primary) {
            return (int) $primary->subject_id === $vendorId;
        }

        return (int) $meeting->kickoffable_id === $vendorId;
    }

    /**
     * True when the caller is the vendor working through its own onboarding,
     * rather than staff operating it. Anything that is not internal staff is
     * treated as the vendor — the safer default for a new role.
     */
    private function vendorDriven(User $actor): bool
    {
        return ! in_array($actor->role, ['admin', 'staff'], true);
    }

    /**
     * The highest step a vendor may currently open: the first one not yet
     * complete. Steps already finished stay reachable, so 1 is always allowed.
     *
     * Derived from stepStatus() rather than a second copy of the rules, so the
     * gate and the progress bar can never disagree about what is done.
     */
    private function furthestReachableStep(TpvOnboarding $onboarding): int
    {
        foreach ($this->stepStatus($onboarding)['steps'] as $s) {
            if (! $s['complete']) {
                return (int) $s['step'];
            }
        }

        return Status::TOTAL_STEPS;
    }

    /** Step 2 — persist the TPV-specific company/contact profile. */
    public function saveProfile(TpvOnboarding $onboarding, array $profile, User $actor): TpvOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }

        if (!empty($profile['profile_photo']) && str_starts_with($profile['profile_photo'], 'data:image/')) {
            try {
                if (preg_match('/data:image\/(?<type>.*?);base64,(?<data>.*)/', $profile['profile_photo'], $matches)) {
                    $type = strtolower($matches['type'] ?? 'jpg');
                    $type = in_array($type, ['png', 'jpeg', 'jpg'], true) ? $type : 'jpg';
                    $data = base64_decode($matches['data']);

                    $filename = 'profile_' . $onboarding->vendor_id . '_' . time() . '.' . $type;
                    $dir = storage_path('app/public/profile_photos');
                    if (!file_exists($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($dir . '/' . $filename, $data);
                    $profile['profile_photo'] = '/storage/profile_photos/' . $filename;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to save base64 profile_photo: ' . $e->getMessage());
            }
        }

        $merged = array_merge($onboarding->profile ?? [], $profile);

        $onboarding->update([
            'profile'      => $merged,
            'current_step' => max($onboarding->current_step, 3),
        ]);

        // Mirror the identity + bank fields onto the vendor master, the same way
        // the wizard already treats the vendor as the source of truth for these.
        $this->mirrorProfileToVendor($onboarding, $merged);

        $onboarding->recordAudit('Profile Saved', $actor);

        Log::channel('tpv')->info('TPV onboarding profile saved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding->fresh(['vendor']);
    }

    /**
     * Copy company name / GST / PAN / email / phone onto the vendor, and upsert
     * the normalized bank account. Only non-empty values are written, so partial/draft saves
     * never wipe existing master data.
     */
    private function mirrorProfileToVendor(TpvOnboarding $onboarding, array $profile): void
    {
        $vendor = $onboarding->vendor;
        if (! $vendor) {
            return;
        }

        $vendorPatch = array_filter([
            'company_name' => $profile['company_name'] ?? null,
            'gst_number'   => $profile['gst_number'] ?? null,
            'pan_number'   => isset($profile['pan_number']) ? strtoupper((string) $profile['pan_number']) : null,
            'email'        => $profile['email'] ?? $profile['contact_email'] ?? null,
            'phone'        => $profile['mobile'] ?? $profile['contact_mobile'] ?? null,
            'website'      => $profile['website'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($vendorPatch !== []) {
            $vendor->update($vendorPatch);
        }

        $bank = array_filter([
            'account_holder' => $profile['bank_account_holder'] ?? null,
            'bank_name'      => $profile['bank_name'] ?? null,
            'account_number' => $profile['bank_account_number'] ?? null,
            'ifsc'           => isset($profile['bank_ifsc']) ? strtoupper((string) $profile['bank_ifsc']) : null,
            'branch'         => $profile['bank_branch'] ?? null,
            'account_type'   => $profile['bank_account_type'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($bank !== []) {
            \App\Models\Vendor\VendorBankAccount::updateOrCreate(
                ['tenant_id' => $onboarding->tenant_id, 'vendor_id' => $vendor->id],
                $bank,
            );
        }
    }

    /**
     * Step 1 — record that the vendor read and accepted the Kickoff document,
     * capturing the originating context. Idempotent: re-accepting keeps the
     * original timestamp. Advances the wizard pointer past the kickoff gate.
     */
    public function acknowledgeKickoff(TpvOnboarding $onboarding, User $actor, array $meta): TpvOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }

        /** @var KickoffPdfService $kickoffPdfService */
        $kickoffPdfService = app(KickoffPdfService::class);
        $meeting = $kickoffPdfService->findKickoffMeeting($onboarding);

        if (! $meeting) {
            throw new BusinessException('Kickoff meeting is not completed or MOM has not been sent yet.');
        }

        $byName = $actor->name ?? $onboarding->vendor?->company_name ?? 'Vendor Representative';

        // The vendor's response to the minutes. Optional, and stored on the
        // MEETING beside the other acknowledgement_* fields — the comment is
        // feedback about the MOM, not about this onboarding record.
        $comment = trim((string) ($meta['comment'] ?? ''));

        // Only the designated signatory signs the MINUTES. Every vendor on a
        // multi-vendor kickoff can now resolve the meeting (that is what makes the
        // MOM visible to them at all), so without this a secondary reading its own
        // Step 1 would put its name on the meeting's single signature line.
        //
        // A secondary still records receipt on ITS OWN onboarding below — that is a
        // different fact from "the minutes were agreed", and gating it would leave
        // every secondary stuck on Step 1 with nothing it is allowed to do.
        $isSignatory = $this->isDesignatedSignatory($meeting, $onboarding);

        if ($isSignatory && ! $meeting->acknowledged_at) {
            $meeting->update([
                'acknowledged_at'         => now(),
                'acknowledged_by_name'    => $byName,
                'acknowledged_ip'         => $meta['ip'] ?? null,
                'acknowledgement_status'  => KickoffMeeting::ACK_ACKNOWLEDGED,
                'acknowledgement_comment' => $comment !== '' ? $comment : null,
                'ack_token'               => null,
            ]);
            $meeting->recordAudit('acknowledged', $actor, 'Kickoff MOM acknowledged via Vendor Portal Step 1', [
                'ip' => $meta['ip'] ?? null, 'browser' => $meta['browser'] ?? null, 'device' => $meta['device'] ?? null,
            ]);
        }

        if (! $onboarding->acknowledged) {
            $onboarding->update([
                'acknowledged'         => true,
                'acknowledged_by'      => $byName,
                'acknowledged_at'      => now(),
                'acknowledged_ip'      => $meta['ip'] ?? null,
                'acknowledged_browser' => $meta['browser'] ?? null,
                'acknowledged_device'  => $meta['device'] ?? null,
                'status'               => $onboarding->status === Status::DRAFT ? Status::IN_PROGRESS : $onboarding->status,
                'current_step'         => max($onboarding->current_step, 2),
            ]);

            $onboarding->recordAudit('Kickoff MOM Accepted', $actor, null, [
                'ip' => $meta['ip'] ?? null, 'browser' => $meta['browser'] ?? null, 'device' => $meta['device'] ?? null,
            ]);

            Log::channel('tpv')->info('TPV kickoff acknowledged', [
                'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id, 'actor_id' => $actor->id,
            ]);
        }

        return $onboarding->fresh(['vendor']);
    }

    /** Audit a Kickoff PDF interaction (viewed / downloaded / printed). */
    public function logKickoffEvent(TpvOnboarding $onboarding, string $event, User $actor, array $meta = []): void
    {
        $action = [
            'viewed'     => 'Kickoff PDF Viewed',
            'downloaded' => 'Kickoff PDF Downloaded',
            'printed'    => 'Kickoff PDF Printed',
        ][$event] ?? null;

        if ($action === null) {
            throw new BusinessException('Unknown kickoff event.');
        }

        $onboarding->recordAudit($action, $actor, null, $meta);
    }

    /**
     * Per-step completion for the wizard UI. Folds the profile, the document
     * checklist and the workflow status into the six presentation steps.
     */
    public function stepStatus(TpvOnboarding $onboarding): array
    {
        $checklist = $this->documentService->checklist($onboarding->vendor);
        $s = $checklist['summary'];

        $profileDone   = ! empty($onboarding->profile);
        $allUploaded   = $s['uploaded'] === $s['required'] && $s['required'] > 0;
        $allReviewed   = $allUploaded && $s['pending'] === 0;
        $allApproved   = $checklist['complete'];
        $submitted     = in_array($onboarding->status, [Status::SUBMITTED, Status::UNDER_REVIEW, Status::APPROVED], true);

        return [
            'current_step' => $onboarding->current_step,
            'documents'    => $checklist,
            'steps' => [
                ['step' => 1, 'key' => 'kickoff',      'label' => 'Kickoff MOM',    'complete' => (bool) $onboarding->acknowledged, 'detail' => $onboarding->acknowledged ? 'Acknowledged' : 'Awaiting acknowledgement'],
                ['step' => 2, 'key' => 'profile',      'label' => 'Company Profile', 'complete' => $profileDone, 'detail' => $profileDone ? 'Saved' : 'Pending'],
                ['step' => 3, 'key' => 'documents',    'label' => 'Documents',       'complete' => $allUploaded, 'detail' => "{$s['uploaded']}/{$s['required']} uploaded"],
                ['step' => 4, 'key' => 'review',       'label' => 'Under Review',    'complete' => $allReviewed && $s['rejected'] === 0, 'detail' => $s['rejected'] > 0 ? "{$s['rejected']} rejected" : ($allReviewed ? 'All reviewed' : "{$s['pending']} pending")],
                ['step' => 5, 'key' => 'confirmation', 'label' => 'Confirmation',    'complete' => $allApproved, 'detail' => "{$s['approved']}/{$s['required']} approved"],
                ['step' => 6, 'key' => 'submission',   'label' => 'Admin Approval',  'complete' => $submitted,   'detail' => $onboarding->status_label],
            ],
        ];
    }

    /**
     * Step 5 → 6. Blocked until every required document for the vendor's type is
     * Approved, mirroring the legacy "final confirmation" gate.
     */
    public function submit(TpvOnboarding $onboarding, User $actor, array $meta = []): TpvOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding has already been submitted.');
        }

        if (empty($onboarding->profile)) {
            throw new BusinessException('Complete the company profile before submitting.');
        }

        $outstanding = $onboarding->outstandingDocuments();
        if ($outstanding !== []) {
            $labels = array_map(fn ($t) => \App\Models\Vendor\VendorDocument::typeLabel($t), $outstanding);
            throw new BusinessException('Outstanding documents: '.implode(', ', $labels));
        }

        // Record the Step-5 declaration + completion context alongside the
        // existing status transition (all additive; the transition is unchanged).
        $onboarding->update([
            'status'                  => Status::SUBMITTED,
            'current_step'            => Status::TOTAL_STEPS,
            'submitted_at'            => now(),
            'declaration_accepted_at' => now(),
            'onboarding_complete'     => true,
            'completed_at'            => now(),
            'completed_ip'            => $meta['ip'] ?? null,
            'completed_browser'       => $meta['browser'] ?? null,
            'completed_device'        => $meta['device'] ?? null,
        ]);

        $onboarding->recordAudit('Declaration Accepted', $actor, null, [
            'ip' => $meta['ip'] ?? null, 'browser' => $meta['browser'] ?? null, 'device' => $meta['device'] ?? null,
        ]);
        $onboarding->recordAudit('Onboarding Completed', $actor, null, ['to' => Status::SUBMITTED]);
        $onboarding->recordAudit('Onboarding Submitted', $actor, null, ['to' => Status::SUBMITTED]);

        Log::channel('tpv')->info('TPV onboarding submitted', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding;
    }

    /**
     * Admin approval — approves onboarding and generates Registration Number.
     */
    /**
     * §10 — the effective onboarding checklist for this vendor, resolved from its
     * dimensions (risk level) and merged with what the admin has ticked so far.
     * Returns the per-item done state, whether it gates activation, and what is
     * still missing.
     */
    public function checklist(TpvOnboarding $onboarding): array
    {
        $resolved = app(\App\Support\Tpv\TpvSettings::class)
            ->checklistFor($this->checklistContext($onboarding), (int) $onboarding->tenant_id);
        $state = $onboarding->checklist_state ?? [];

        $items = array_map(fn ($label) => [
            'item' => $label,
            'done' => (bool) ($state[$label] ?? false),
        ], $resolved['items']);
        $missing = array_values(array_filter($resolved['items'], fn ($l) => empty($state[$l])));

        return [
            'items'            => $items,
            'gates_activation' => (bool) $resolved['gates_activation'],
            'complete'         => $missing === [],
            'missing'          => $missing,
        ];
    }

    /** The [dimension => value] context the checklist rules match against (§10). */
    private function checklistContext(TpvOnboarding $onboarding): array
    {
        $vendor = $onboarding->vendor;

        return array_filter([
            'risk_level' => $vendor?->risk_level,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Admin ticks (or unticks) checklist items; merges onto the stored state (§10). */
    public function setChecklist(TpvOnboarding $onboarding, array $state): TpvOnboarding
    {
        $current = $onboarding->checklist_state ?? [];
        foreach ($state as $item => $done) {
            $current[(string) $item] = (bool) $done;
        }
        $onboarding->update(['checklist_state' => $current]);

        return $onboarding->fresh() ?? $onboarding;
    }

    public function approve(TpvOnboarding $onboarding, User $actor, ?string $remarks = null): TpvOnboarding
    {
        // §10 — the general onboarding checklist gates activation. If it is
        // configured to gate and any required item is still unticked, refuse to
        // activate (the doc's "checklist must be complete before activation"). Skip
        // the gate when the vendor is already Active so re-approval stays idempotent.
        $checklist = $this->checklist($onboarding);
        if ($checklist['gates_activation'] && ! $checklist['complete']
            && $onboarding->vendor?->status !== VendorStatus::ACTIVE) {
            throw new BusinessException(
                'Onboarding checklist incomplete — the following must be ticked before activation: '
                .implode(', ', $checklist['missing']).'.'
            );
        }

        $registrationNumber = $onboarding->registration_number
            ?: $this->registrationNumbers->generate($onboarding->tenant_id);

        $onboarding->update([
            'status'              => Status::APPROVED,
            'approved_at'         => now(),
            'approved_by'         => $actor->id,
            'remarks'             => $remarks ?? $onboarding->remarks,
            'registration_number' => $registrationNumber,
        ]);
        // Activating the vendor IS the approval — everything downstream keys off
        // vendor.status, not the onboarding's. The portal reveals "My Workforce"
        // on Active, TpvWorkerService::blockers() refuses a site badge while the
        // vendor is anything else, and GateScanService turns them away at the gate.
        //
        // Route the activation through VendorService::updateStatus so the FULL
        // activation fires — not just a status flag: it activates the portal login
        // (mirrors user.status + starts the temporary access window via
        // TpvAccessService) and sends the logged, once-only activation email via
        // TpvActivationNotifier. A bare $vendor->update(['status'=>Active]) here
        // skipped all of that, leaving the login unprovisioned and no real email.
        $vendor = $onboarding->vendor;
        if ($vendor) {
            $vendor->update(['registration_number' => $registrationNumber]);
            app(\App\Services\Vendor\VendorService::class)
                ->updateStatus($vendor, VendorStatus::ACTIVE, $actor, $remarks);
        }

        // Issue the formal Work Start Letter (HSSE approval to commence work). It
        // is generated after activation and stored against the onboarding; the
        // service swallows its own errors so a letter failure never rolls back the
        // approval. Downloadable by admin and vendor thereafter.
        if ($vendor) {
            app(\App\Services\Tpv\WorkStartLetterService::class)->generate($onboarding->fresh() ?? $onboarding);
        }

        $onboarding->recordAudit('Onboarding Approved', $actor, $remarks, [
            'to' => Status::APPROVED, 'registration_number' => $registrationNumber,
        ]);

        Log::channel('tpv')->info('TPV onboarding approved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
            'actor_id' => $actor->id, 'registration_number' => $registrationNumber,
        ]);

        // Activation email is sent by VendorService (TpvActivationNotifier), so we
        // deliberately do NOT dispatch the onboarding "approved" mail here — that
        // would double-send.

        return $onboarding;
    }

    /** Decline the onboarding outright, with a mandatory reason. */
    public function reject(TpvOnboarding $onboarding, User $actor, string $remarks): TpvOnboarding
    {
        $onboarding->update(['status' => Status::REJECTED, 'remarks' => $remarks]);
        // Reflect the rejection on the vendor account too, so the portal dashboard
        // and vendor status show Rejected (the login stays reachable so the vendor
        // can read the reason). The vendor is not Active, so no workforce access.
        $onboarding->vendor?->update(['status' => VendorStatus::REJECTED]);
        $onboarding->recordAudit('Onboarding Rejected', $actor, $remarks, ['to' => Status::REJECTED]);

        Log::channel('tpv')->info('TPV onboarding rejected', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        $this->dispatchStatusNotification($onboarding, Status::REJECTED, $remarks);

        return $onboarding;
    }

    /** Pause the decision pending clarification, with a mandatory reason. */
    public function hold(TpvOnboarding $onboarding, User $actor, string $reason): TpvOnboarding
    {
        $onboarding->update(['status' => Status::ON_HOLD, 'hold_reason' => $reason, 'remarks' => $reason]);
        // Put the vendor account on hold too — not Active, so workforce/gate access
        // is withheld until the hold is released, while the login stays reachable.
        $onboarding->vendor?->update(['status' => VendorStatus::ON_HOLD]);
        $onboarding->recordAudit('Onboarding On Hold', $actor, $reason, ['to' => Status::ON_HOLD]);

        Log::channel('tpv')->info('TPV onboarding put on hold', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        $this->dispatchStatusNotification($onboarding, Status::ON_HOLD, $reason);

        return $onboarding;
    }

    /** Release a held onboarding back into the review queue. */
    public function release(TpvOnboarding $onboarding, User $actor): TpvOnboarding
    {
        if ($onboarding->status !== Status::ON_HOLD) {
            throw new BusinessException('Only an on-hold onboarding can be released.');
        }

        $onboarding->update(['status' => Status::UNDER_REVIEW, 'hold_reason' => null]);
        // Lift the account hold back to a reviewable (non-active) state so the
        // onboarding decision can be taken again.
        if ($onboarding->vendor && $onboarding->vendor->status === VendorStatus::ON_HOLD) {
            $onboarding->vendor->update(['status' => VendorStatus::PENDING_APPROVAL]);
        }
        $onboarding->recordAudit('Onboarding Released', $actor, null, ['to' => Status::UNDER_REVIEW]);

        Log::channel('tpv')->info('TPV onboarding released from hold', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding;
    }

    /** Send the wizard back to the vendor for correction. */
    public function requestResubmit(TpvOnboarding $onboarding, User $actor, string $remarks): TpvOnboarding
    {
        $onboarding->update(['status' => Status::RESUBMIT_REQUIRED, 'remarks' => $remarks]);
        $onboarding->recordAudit('Onboarding Resubmit Requested', $actor, $remarks, ['to' => Status::RESUBMIT_REQUIRED]);

        Log::channel('tpv')->info('TPV onboarding resubmit requested', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        $this->dispatchStatusNotification($onboarding, Status::RESUBMIT_REQUIRED, $remarks);

        return $onboarding;
    }

    protected function dispatchStatusNotification(TpvOnboarding $onboarding, string $status, ?string $remarks = null): void
    {
        $vendor = $onboarding->vendor;
        if (! $vendor) return;

        $toEmail = $vendor->email ?? $vendor->user?->email;
        $companyName = $vendor->company_name ?? 'Vendor Partner';
        $regNo = $onboarding->registration_number ?? $vendor->registration_number ?? 'Generated';

        $subject = '';
        $body = '';

        if ($status === Status::APPROVED) {
            $subject = "🎉 TPV Onboarding Approved — You are Ready to Add Workforce ({$companyName})";
            $body = "Dear {$companyName},\n\n" .
                "Congratulations! Your Third-Party Vendor (TPV) Onboarding has been reviewed and APPROVED by our administration team.\n\n" .
                "Vendor Registration Number: {$regNo}\n" .
                "Onboarding Status: APPROVED\n\n" .
                "Your onboarding is now complete. You can log into your Vendor Portal and start adding your workforce workers, submitting medical records, induction details, and issuing site passes.\n\n" .
                "Access Portal: " . \App\Support\FrontendUrl::to('/auth/login?role=third_party_vendor') . "\n\n" .
                "Best regards,\nTPV Vendor Management Team";
        } elseif ($status === Status::REJECTED) {
            $subject = "⚠️ TPV Onboarding Update: Application Rejected ({$companyName})";
            $body = "Dear {$companyName},\n\n" .
                "Your Third-Party Vendor (TPV) Onboarding application has been reviewed and REJECTED by the administration team.\n\n" .
                "Admin Remarks / Reason:\n" . ($remarks ?: 'No specific reason specified.') . "\n\n" .
                "Please contact the site administrator for further assistance.\n\n" .
                "Best regards,\nTPV Vendor Management Team";
        } elseif ($status === Status::ON_HOLD) {
            $subject = "⏸️ TPV Onboarding Update: Application Placed On Hold ({$companyName})";
            $body = "Dear {$companyName},\n\n" .
                "Your Third-Party Vendor (TPV) Onboarding application has been placed ON HOLD pending administrator review.\n\n" .
                "Admin Remarks / Reason:\n" . ($remarks ?: 'Review pending further documentation or clarification.') . "\n\n" .
                "Please contact the administrator for further instructions.\n\n" .
                "Best regards,\nTPV Vendor Management Team";
        } elseif ($status === Status::RESUBMIT_REQUIRED) {
            $subject = "🔁 TPV Onboarding Update: Revision Requested ({$companyName})";
            $body = "Dear {$companyName},\n\n" .
                "Your Third-Party Vendor (TPV) Onboarding application requires updates or corrections.\n\n" .
                "Admin Remarks:\n" . ($remarks ?: 'Please update the requested details.') . "\n\n" .
                "Please log into your Vendor Portal to make the requested revisions and resubmit.\n\n" .
                "Best regards,\nTPV Vendor Management Team";
        }

        if (! empty($toEmail) && ! empty($subject)) {
            // Through NotificationService so this uses the tenant's own SMTP
            // (Settings -> Email) like every other vendor mail, instead of the
            // global .env account. It records a status and never throws.
            $this->channels->email(
                $toEmail, $subject, $body,
                ['vendor_id' => $vendor->id, 'event' => 'onboarding_status'],
                $vendor->tenant_id,
            );
        }

        Log::channel('tpv')->info("Notification alert logged for {$companyName} - Status: {$status}");
    }

    public function destroy(TpvOnboarding $onboarding): void
    {
        $onboarding->delete();

        Log::channel('tpv')->info('TPV onboarding deleted', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'      => TpvOnboarding::forTenant($tenantId)->count(),
            'in_progress' => TpvOnboarding::forTenant($tenantId)->where('status', Status::IN_PROGRESS)->count(),
            'awaiting'   => TpvOnboarding::forTenant($tenantId)->awaitingApproval()->count(),
            'approved'   => TpvOnboarding::forTenant($tenantId)->where('status', Status::APPROVED)->count(),
            'rejected'   => TpvOnboarding::forTenant($tenantId)->where('status', Status::REJECTED)->count(),
        ];
    }
}
