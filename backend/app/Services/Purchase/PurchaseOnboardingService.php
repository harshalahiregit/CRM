<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Services\Purchase\PurchaseApprovalService;
use App\Services\Purchase\PurchaseDocumentService;
use App\Support\Purchase\PurchaseApprovalStage;
use App\Support\Purchase\PurchaseOnboardingStatus as Status;
use App\Support\Purchase\PurchaseVendorStatus as VendorStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Purchase-vendor onboarding — over the Purchase Vendor master (PurchaseVendor)
 * only. Uses the Purchase-owned PurchaseDocumentService for the document
 * checklist and PurchaseApprovalService for the approval chain. Fully independent
 * of TPV and of the shared Vendor architecture.
 */
class PurchaseOnboardingService
{
    public function __construct(
        private PurchaseDocumentService $documentService,
        private PurchaseRegistrationNumberService $registrationNumbers,
        private PurchaseApprovalService $approvals,
        private \App\Services\Notifications\NotificationService $channels,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $query = PurchaseOnboarding::forTenant($tenantId)
            ->with(['vendor:id,purchase_vendor_code,company_name,vendor_type,status']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['purchase_vendor_id'])) {
            $query->where('purchase_vendor_id', $filters['purchase_vendor_id']);
        }

        return $query->latest()->get();
    }

    /**
     * The six wizard methods below are reachable from BOTH sides of the module:
     * an admin (a User) and the vendor itself from the portal, where the
     * authenticated identity is a PurchaseVendor — the portal issues a token whose
     * tokenable is purchase_vendors, so `$request->user()` is never a User there.
     *
     * These two helpers normalise whichever arrives. `created_by`/`approved_by` are
     * users.id columns, so a PurchaseVendor must resolve to NULL rather than write
     * its own id into a user reference; the audit trail keeps the vendor visible
     * through recordAudit()'s $actorLabel instead of losing the actor entirely.
     *
     * The admin-only transitions (approve/reject/hold/release/requestResubmit) stay
     * typed `User` on purpose — a vendor may never invoke them, and the type is the
     * guard that says so.
     */
    private function actorUser(User|PurchaseVendor|null $actor): ?User
    {
        return $actor instanceof User ? $actor : null;
    }

    /**
     * Display name for the audit trail. Null for a User — AuditLogService already
     * snapshots `$actor->name` in that case and the label must not override it.
     * A PurchaseVendor signs as its company, since it has no `name` column.
     */
    private function actorLabel(User|PurchaseVendor|null $actor): ?string
    {
        if (! $actor instanceof PurchaseVendor) {
            return null;
        }

        return trim(($actor->company_name ?: 'Vendor').' (Vendor Portal)');
    }

    /** Start the 6-step wizard for a purchase vendor. One onboarding per vendor. */
    public function create(array $data, User|PurchaseVendor $actor): PurchaseOnboarding
    {
        $tenantId = $actor->tenant_id;
        $vendor = PurchaseVendor::forTenant($tenantId)->find($data['purchase_vendor_id']);

        if (! $vendor) {
            throw new BusinessException('Purchase vendor not found.', 404);
        }

        $existing = PurchaseOnboarding::forTenant($tenantId)->where('purchase_vendor_id', $vendor->id)->first();
        if ($existing) {
            return $existing->fresh(['vendor']);
        }

        $onboarding = PurchaseOnboarding::create([
            'purchase_vendor_id' => $vendor->id,
            'tenant_id'          => $tenantId,
            'created_by'         => $this->actorUser($actor)?->id,
            'current_step'       => 1,
            'status'             => Status::IN_PROGRESS,
        ]);

        $onboarding->recordAudit('Purchase Onboarding Started', $this->actorUser($actor), null,
            ['purchase_vendor_code' => $vendor->purchase_vendor_code], $this->actorLabel($actor));

        Log::channel('purchase')->info('Purchase onboarding started', [
            'onboarding_id' => $onboarding->id, 'purchase_vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        return $onboarding->fresh(['vendor']);
    }

    public function setStep(PurchaseOnboarding $onboarding, int $step, User|PurchaseVendor $actor): PurchaseOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }
        if ($step < 1 || $step > Status::TOTAL_STEPS) {
            throw new BusinessException('Step must be between 1 and '.Status::TOTAL_STEPS.'.');
        }

        // A vendor may only move forward into the first step that is not yet done;
        // going back to re-read a finished one is always allowed. Enforced at the
        // service because the endpoint is the boundary — the wizard hiding a button
        // is not a rule.
        //
        // Staff/admin are exempt: step 3 counts as complete only once EVERY required
        // document is uploaded, so gating them would stop a reviewer opening step 4
        // to review the documents that have actually arrived. The actor here is a
        // PurchaseVendor precisely when the portal is driving it.
        if ($actor instanceof PurchaseVendor) {
            $furthest = $this->furthestReachableStep($onboarding);

            if ($step > $furthest) {
                throw new BusinessException('Complete step '.$furthest.' before moving on.', 422);
            }
        }

        $onboarding->update(['current_step' => $step]);
        $onboarding->recordAudit('Onboarding Step Changed', $this->actorUser($actor), null,
            ['step' => $step], $this->actorLabel($actor));

        return $onboarding;
    }

    /**
     * The highest step a vendor may currently open: the first one not yet complete.
     * Finished steps stay reachable, so step 1 is always allowed.
     *
     * Read from stepStatus() rather than a second copy of the rules, so the gate and
     * the progress bar can never disagree about what is done.
     */
    private function furthestReachableStep(PurchaseOnboarding $onboarding): int
    {
        foreach ($this->stepStatus($onboarding)['steps'] as $s) {
            if (! $s['complete']) {
                return (int) $s['step'];
            }
        }

        return Status::TOTAL_STEPS;
    }

    /** Step 2 — persist the company/contact profile and mirror to the vendor. */
    public function saveProfile(PurchaseOnboarding $onboarding, array $profile, User|PurchaseVendor $actor): PurchaseOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }

        $merged = array_merge($onboarding->profile ?? [], $profile);

        $onboarding->update([
            'profile'      => $merged,
            'current_step' => max($onboarding->current_step, 3),
        ]);

        $this->mirrorProfileToVendor($onboarding, $merged);
        $onboarding->recordAudit('Profile Saved', $this->actorUser($actor), null, [], $this->actorLabel($actor));

        Log::channel('purchase')->info('Purchase onboarding profile saved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding->fresh(['vendor']);
    }

    /** Copy identity + bank fields onto the vendor; only non-empty values written. */
    private function mirrorProfileToVendor(PurchaseOnboarding $onboarding, array $profile): void
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

        // Bank details are retained on the onboarding profile JSON (Purchase-owned);
        // Purchase does not write to the shared vendor_bank_accounts table.
    }

    /** Per-step completion + document checklist (the wizard's live state). */
    public function stepStatus(PurchaseOnboarding $onboarding): array
    {
        $checklist = $this->documentService->checklist($onboarding->vendor);
        $s = $checklist['summary'];

        $profileDone = ! empty($onboarding->profile);
        $allUploaded = $s['uploaded'] === $s['required'] && $s['required'] > 0;
        $allReviewed = $allUploaded && $s['pending'] === 0;
        $allApproved = $checklist['complete'];
        $submitted   = in_array($onboarding->status, [Status::SUBMITTED, Status::UNDER_REVIEW, Status::APPROVED], true);

        // Category drives whether the vendor needs the Workforce step; the portal
        // renders the flow from this (never hardcoded on the frontend).
        $cfg = \App\Support\Purchase\PurchaseVendorCategoryConfig::resolve($onboarding->vendor->category ?? null);
        $workforce = $cfg['requires_workforce'] && $onboarding->vendor
            ? app(\App\Services\Purchase\PurchaseWorkforceService::class)->summary($onboarding->vendor)
            : null;

        return [
            'current_step'       => $onboarding->current_step,
            'documents'          => $checklist,
            'category'           => $cfg['category'],
            'requires_workforce' => $cfg['requires_workforce'],
            'onboarding_steps'   => $cfg['onboarding_steps'],
            'workforce'          => $workforce,
            'steps' => [
                ['step' => 1, 'key' => 'kickoff',      'label' => 'Kickoff MOM',     'complete' => (bool) $onboarding->acknowledged, 'detail' => $onboarding->acknowledged ? 'Acknowledged' : 'Awaiting acknowledgement'],
                ['step' => 2, 'key' => 'profile',      'label' => 'Company Profile', 'complete' => $profileDone, 'detail' => $profileDone ? 'Saved' : 'Pending'],
                ['step' => 3, 'key' => 'documents',    'label' => 'Documents',       'complete' => $allUploaded, 'detail' => "{$s['uploaded']}/{$s['required']} uploaded"],
                ['step' => 4, 'key' => 'review',       'label' => 'Under Review',    'complete' => $allReviewed && $s['rejected'] === 0, 'detail' => $s['rejected'] > 0 ? "{$s['rejected']} rejected" : ($allReviewed ? 'All reviewed' : "{$s['pending']} pending")],
                ['step' => 5, 'key' => 'confirmation', 'label' => 'Confirmation',    'complete' => $allApproved, 'detail' => "{$s['approved']}/{$s['required']} approved"],
                ['step' => 6, 'key' => 'submission',   'label' => 'Admin Approval',  'complete' => $submitted,   'detail' => $onboarding->status_label],
            ],
        ];
    }

    /** Step 5 → 6. Blocked until every required document is Approved. */
    public function submit(PurchaseOnboarding $onboarding, User|PurchaseVendor $actor, array $meta = []): PurchaseOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding has already been submitted.');
        }
        if (empty($onboarding->profile)) {
            throw new BusinessException('Complete the company profile before submitting.');
        }

        $outstanding = $onboarding->outstandingDocuments();
        if ($outstanding !== []) {
            $labels = array_map(fn ($t) => \App\Models\Purchase\PurchaseDocument::typeLabel($t), $outstanding);
            throw new BusinessException('Outstanding documents: '.implode(', ', $labels));
        }

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

        $onboarding->recordAudit('Onboarding Submitted', $this->actorUser($actor), null,
            ['to' => Status::SUBMITTED], $this->actorLabel($actor));

        Log::channel('purchase')->info('Purchase onboarding submitted', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding;
    }

    /** Admin approval — sets the vendor Active + mints a Purchase reg-number. */
    public function approve(PurchaseOnboarding $onboarding, User $actor, ?string $remarks = null): PurchaseOnboarding
    {
        if (! in_array($onboarding->status, Status::DECIDABLE, true)) {
            throw new BusinessException('Only a submitted onboarding can be approved.');
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

        // Activate the Purchase vendor for procurement (Purchase-owned entity).
        // Route the activation through PurchaseVendorService::updateStatus so the
        // FULL activation fires — portal login provisioning, the temporary access
        // window and the logged, once-only activation e-mail — mirroring how
        // TpvOnboardingService::approve goes through VendorService. A bare
        // status flip here (the old behaviour) left the login unprovisioned and
        // sent no real activation mail.
        $vendor = $onboarding->vendor;
        $vendor->update([
            'approved_at'         => now(),
            'approved_by'         => $actor->id,
            'registration_number' => $vendor->registration_number ?: $registrationNumber,
        ]);
        app(PurchaseVendorService::class)->updateStatus($vendor, VendorStatus::ACTIVE, $actor, $remarks);

        // Record the full five-stage chain in the Purchase-owned approval engine
        // (Registration → Document → Commercial → Purchase → Final Activation).
        $this->approvals->approveAll($onboarding, $actor, $remarks);

        $onboarding->recordAudit('Onboarding Approved', $actor, $remarks, [
            'to' => Status::APPROVED, 'registration_number' => $registrationNumber,
        ]);

        Log::channel('purchase')->info('Purchase onboarding approved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
            'actor_id' => $actor->id, 'registration_number' => $registrationNumber,
        ]);

        // The activation e-mail is sent by PurchaseVendorService (the activation
        // notifier), so we deliberately do NOT also send the onboarding "approved"
        // mail here — that would double-send. Reject/hold/resubmit still notify.

        return $onboarding;
    }

    public function reject(PurchaseOnboarding $onboarding, User $actor, string $remarks): PurchaseOnboarding
    {
        $onboarding->update(['status' => Status::REJECTED, 'remarks' => $remarks]);
        // Reflect the rejection on the vendor account so the portal and vendor
        // status show Rejected — not Active, so no procurement access. Mirrors
        // TpvOnboardingService::reject.
        $onboarding->vendor?->update(['status' => VendorStatus::REJECTED]);
        // Record the rejection at the Final Activation stage of the Purchase-owned chain.
        $this->approvals->rejectAt($onboarding, PurchaseApprovalStage::ACTIVATION, $actor, $remarks);
        $onboarding->recordAudit('Onboarding Rejected', $actor, $remarks, ['to' => Status::REJECTED]);
        $this->notify($onboarding, Status::REJECTED, $remarks);

        return $onboarding;
    }

    public function hold(PurchaseOnboarding $onboarding, User $actor, string $reason): PurchaseOnboarding
    {
        $onboarding->update(['status' => Status::ON_HOLD, 'hold_reason' => $reason, 'remarks' => $reason]);
        // Put the vendor account on hold too — not Active, so procurement/portal
        // access is withheld until the hold is released. Mirrors TpvOnboardingService::hold.
        $onboarding->vendor?->update(['status' => VendorStatus::ON_HOLD]);
        $onboarding->recordAudit('Onboarding On Hold', $actor, $reason, ['to' => Status::ON_HOLD]);
        $this->notify($onboarding, Status::ON_HOLD, $reason);

        return $onboarding;
    }

    public function release(PurchaseOnboarding $onboarding, User $actor): PurchaseOnboarding
    {
        if ($onboarding->status !== Status::ON_HOLD) {
            throw new BusinessException('Only an on-hold onboarding can be released.');
        }

        $onboarding->update(['status' => Status::UNDER_REVIEW, 'hold_reason' => null]);
        // Lift the account hold back to a reviewable (non-active) state so the
        // onboarding decision can be taken again. Mirrors TpvOnboardingService::release.
        if ($onboarding->vendor && $onboarding->vendor->status === VendorStatus::ON_HOLD) {
            $onboarding->vendor->update(['status' => VendorStatus::PENDING_APPROVAL]);
        }
        $onboarding->recordAudit('Onboarding Released', $actor, null, ['to' => Status::UNDER_REVIEW]);

        return $onboarding;
    }

    public function requestResubmit(PurchaseOnboarding $onboarding, User $actor, string $remarks): PurchaseOnboarding
    {
        $onboarding->update(['status' => Status::RESUBMIT_REQUIRED, 'remarks' => $remarks]);
        $onboarding->recordAudit('Onboarding Resubmit Requested', $actor, $remarks, ['to' => Status::RESUBMIT_REQUIRED]);
        $this->notify($onboarding, Status::RESUBMIT_REQUIRED, $remarks);

        return $onboarding;
    }

    public function destroy(PurchaseOnboarding $onboarding): void
    {
        $onboarding->delete();

        Log::channel('purchase')->info('Purchase onboarding deleted', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'       => PurchaseOnboarding::forTenant($tenantId)->count(),
            'in_progress' => PurchaseOnboarding::forTenant($tenantId)->where('status', Status::IN_PROGRESS)->count(),
            'awaiting'    => PurchaseOnboarding::forTenant($tenantId)->awaitingApproval()->count(),
            'approved'    => PurchaseOnboarding::forTenant($tenantId)->where('status', Status::APPROVED)->count(),
            'rejected'    => PurchaseOnboarding::forTenant($tenantId)->where('status', Status::REJECTED)->count(),
        ];
    }

    /* ── Step 1 — kickoff (Purchase-owned kickoff engine) ───────────────── */

    /** The Purchase kickoff meeting attached to this onboarding's vendor, if any. */
    public function resolveKickoffMeeting(PurchaseOnboarding $onboarding): ?\App\Models\Purchase\PurchaseKickoffMeeting
    {
        if ($onboarding->kickoff_meeting_id) {
            $m = \App\Models\Purchase\PurchaseKickoffMeeting::forTenant($onboarding->tenant_id)->find($onboarding->kickoff_meeting_id);
            if ($m) {
                return $m;
            }
        }

        return \App\Models\Purchase\PurchaseKickoffMeeting::forTenant($onboarding->tenant_id)
            ->where('purchase_vendor_id', $onboarding->purchase_vendor_id)
            ->latest()
            ->first();
    }

    /** Record the vendor's acknowledgement of the kickoff MOM (idempotent). */
    public function acknowledgeKickoff(PurchaseOnboarding $onboarding, User|PurchaseVendor $actor, array $meta): PurchaseOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }
        $meeting = $this->resolveKickoffMeeting($onboarding);
        if (! $meeting) {
            throw new BusinessException('Kickoff meeting is not completed or MOM has not been sent yet.');
        }

        // A PurchaseVendor has no `name` column — it signs as its company.
        $byName = ($actor instanceof User ? $actor->name : $actor->company_name)
            ?: ($onboarding->vendor?->company_name ?: 'Vendor Representative');
        if (! $meeting->acknowledged_at) {
            $meeting->update([
                'acknowledged_at'      => now(),
                'acknowledged_by_name' => $byName,
                'acknowledged_ip'      => $meta['ip'] ?? null,
                'ack_token'            => null,
            ]);
        }
        if (! $onboarding->acknowledged) {
            $onboarding->update([
                'acknowledged'    => true,
                'acknowledged_by' => $byName,
                'acknowledged_at' => now(),
                'acknowledged_ip' => $meta['ip'] ?? null,
                'status'          => $onboarding->status === Status::DRAFT ? Status::IN_PROGRESS : $onboarding->status,
                'current_step'    => max($onboarding->current_step, 2),
            ]);
            $onboarding->recordAudit('Kickoff MOM Accepted', $this->actorUser($actor), null,
                $meta, $this->actorLabel($actor));
        }

        return $onboarding->fresh(['vendor']);
    }

    /** Audit a Kickoff PDF interaction (viewed / downloaded / printed). */
    public function logKickoffEvent(PurchaseOnboarding $onboarding, string $event, User|PurchaseVendor $actor, array $meta = []): void
    {
        $action = ['viewed' => 'Kickoff PDF Viewed', 'downloaded' => 'Kickoff PDF Downloaded', 'printed' => 'Kickoff PDF Printed'][$event] ?? null;
        if ($action === null) {
            throw new BusinessException('Unknown kickoff event.');
        }
        $onboarding->recordAudit($action, $this->actorUser($actor), null, $meta, $this->actorLabel($actor));
    }

    /** Best-effort status email to the vendor (procurement-flavoured copy). */
    protected function notify(PurchaseOnboarding $onboarding, string $status, ?string $remarks = null): void
    {
        $vendor = $onboarding->vendor;
        if (! $vendor) {
            return;
        }

        $toEmail = $vendor->email ?? $vendor->user?->email;
        $company = $vendor->company_name ?? 'Vendor Partner';
        $regNo   = $onboarding->registration_number ?? $vendor->registration_number ?? 'Generated';

        [$subject, $body] = match ($status) {
            Status::APPROVED => [
                "Purchase Vendor Onboarding Approved ({$company})",
                "Dear {$company},\n\nYour Purchase Vendor onboarding has been APPROVED.\n\nVendor Registration Number: {$regNo}\n\nYou are now an active procurement vendor.\n\nRegards,\nVendor Management Team",
            ],
            Status::REJECTED => [
                "Purchase Vendor Onboarding Rejected ({$company})",
                "Dear {$company},\n\nYour Purchase Vendor onboarding has been REJECTED.\n\nReason:\n".($remarks ?: 'No specific reason specified.')."\n\nRegards,\nVendor Management Team",
            ],
            Status::ON_HOLD => [
                "Purchase Vendor Onboarding On Hold ({$company})",
                "Dear {$company},\n\nYour Purchase Vendor onboarding has been placed ON HOLD.\n\nReason:\n".($remarks ?: 'Review pending.')."\n\nRegards,\nVendor Management Team",
            ],
            Status::RESUBMIT_REQUIRED => [
                "Purchase Vendor Onboarding — Revision Requested ({$company})",
                "Dear {$company},\n\nYour Purchase Vendor onboarding needs corrections.\n\nRemarks:\n".($remarks ?: 'Please update the requested details.')."\n\nRegards,\nVendor Management Team",
            ],
            default => ['', ''],
        };

        if (! empty($toEmail) && $subject !== '') {
            // Tenant SMTP (Settings -> Email) rather than the global .env
            // account; records a status and never throws.
            $this->channels->email(
                $toEmail, $subject, $body,
                ['purchase_vendor_id' => $vendor->id, 'event' => 'onboarding_status'],
                $vendor->tenant_id,
            );
        }

        Log::channel('purchase')->info("Notification logged for {$company} - Status: {$status}");
    }
}
