<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorDocumentService;
use App\Support\Purchase\PurchaseOnboardingStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Purchase-vendor onboarding — the procurement mirror of TpvOnboardingService,
 * over the shared Vendor master (engagement 'purchase'). Reuses the shared
 * VendorDocumentService for the document checklist. Deliberately never touches
 * TpvOnboarding / VendorService::approve(), so TPV behaviour is unaffected and
 * no tpv_onboardings row is created for a purchase vendor.
 */
class PurchaseOnboardingService
{
    public function __construct(
        private VendorDocumentService $documentService,
        private PurchaseRegistrationNumberService $registrationNumbers,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $query = PurchaseOnboarding::forTenant($tenantId)
            ->with(['vendor:id,vendor_code,company_name,vendor_type,status']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        return $query->latest()->get();
    }

    /** Start the 6-step wizard for a purchase vendor. One onboarding per vendor. */
    public function create(array $data, User $actor): PurchaseOnboarding
    {
        $tenantId = $actor->tenant_id;
        $vendor = Vendor::forTenant($tenantId)->find($data['vendor_id']);

        if (! $vendor) {
            throw new BusinessException('Vendor not found.', 404);
        }

        $existing = PurchaseOnboarding::forTenant($tenantId)->where('vendor_id', $vendor->id)->first();
        if ($existing) {
            return $existing->fresh(['vendor']);
        }

        $onboarding = PurchaseOnboarding::create([
            'vendor_id'    => $vendor->id,
            'tenant_id'    => $tenantId,
            'created_by'   => $actor->id,
            'current_step' => 1,
            'status'       => Status::IN_PROGRESS,
        ]);

        // Tag the vendor as engaged for Purchase without disturbing an existing
        // TPV engagement — one vendor can be both.
        $vendor->update(['engagements' => array_values(array_unique([...($vendor->engagements ?? []), 'purchase']))]);

        $onboarding->recordAudit('Purchase Onboarding Started', $actor, null, ['vendor_code' => $vendor->vendor_code]);

        Log::channel('purchase')->info('Purchase onboarding started', [
            'onboarding_id' => $onboarding->id, 'vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        return $onboarding->fresh(['vendor']);
    }

    public function setStep(PurchaseOnboarding $onboarding, int $step, User $actor): PurchaseOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }
        if ($step < 1 || $step > Status::TOTAL_STEPS) {
            throw new BusinessException('Step must be between 1 and '.Status::TOTAL_STEPS.'.');
        }

        $onboarding->update(['current_step' => $step]);
        $onboarding->recordAudit('Onboarding Step Changed', $actor, null, ['step' => $step]);

        return $onboarding;
    }

    /** Step 2 — persist the company/contact profile and mirror to the vendor. */
    public function saveProfile(PurchaseOnboarding $onboarding, array $profile, User $actor): PurchaseOnboarding
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
        $onboarding->recordAudit('Profile Saved', $actor);

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

        return [
            'current_step' => $onboarding->current_step,
            'documents'    => $checklist,
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
    public function submit(PurchaseOnboarding $onboarding, User $actor, array $meta = []): PurchaseOnboarding
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

        $onboarding->recordAudit('Onboarding Submitted', $actor, null, ['to' => Status::SUBMITTED]);

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

        // Activate the vendor for procurement. Purchase-specific: this does NOT
        // route through VendorService::approve() (which is TPV-flavoured).
        $onboarding->vendor->update([
            'status'              => \App\Support\Vendor\VendorStatus::ACTIVE,
            'approved_at'         => now(),
            'approved_by'         => $actor->id,
            'registration_number' => $onboarding->vendor->registration_number ?: $registrationNumber,
        ]);

        $onboarding->recordAudit('Onboarding Approved', $actor, $remarks, [
            'to' => Status::APPROVED, 'registration_number' => $registrationNumber,
        ]);

        Log::channel('purchase')->info('Purchase onboarding approved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
            'actor_id' => $actor->id, 'registration_number' => $registrationNumber,
        ]);

        $this->notify($onboarding, Status::APPROVED, $remarks);

        return $onboarding;
    }

    public function reject(PurchaseOnboarding $onboarding, User $actor, string $remarks): PurchaseOnboarding
    {
        $onboarding->update(['status' => Status::REJECTED, 'remarks' => $remarks]);
        $onboarding->recordAudit('Onboarding Rejected', $actor, $remarks, ['to' => Status::REJECTED]);
        $this->notify($onboarding, Status::REJECTED, $remarks);

        return $onboarding;
    }

    public function hold(PurchaseOnboarding $onboarding, User $actor, string $reason): PurchaseOnboarding
    {
        $onboarding->update(['status' => Status::ON_HOLD, 'hold_reason' => $reason, 'remarks' => $reason]);
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

    /* ── Step 1 — kickoff (reuses the shared KickoffMeeting engine) ─────── */

    /** The kickoff meeting attached to this onboarding's vendor, if any. */
    public function resolveKickoffMeeting(PurchaseOnboarding $onboarding): ?\App\Models\Shared\KickoffMeeting
    {
        if ($onboarding->kickoff_meeting_id) {
            $m = \App\Models\Shared\KickoffMeeting::forTenant($onboarding->tenant_id)->find($onboarding->kickoff_meeting_id);
            if ($m) {
                return $m;
            }
        }

        return \App\Models\Shared\KickoffMeeting::forTenant($onboarding->tenant_id)
            ->whereIn('kickoffable_type', [\App\Models\Vendor\Vendor::class, 'vendor'])
            ->where('kickoffable_id', $onboarding->vendor_id)
            ->latest()
            ->first();
    }

    /** Record the vendor's acknowledgement of the kickoff MOM (idempotent). */
    public function acknowledgeKickoff(PurchaseOnboarding $onboarding, User $actor, array $meta): PurchaseOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }
        $meeting = $this->resolveKickoffMeeting($onboarding);
        if (! $meeting) {
            throw new BusinessException('Kickoff meeting is not completed or MOM has not been sent yet.');
        }

        $byName = $actor->name ?? $onboarding->vendor?->company_name ?? 'Vendor Representative';
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
            $onboarding->recordAudit('Kickoff MOM Accepted', $actor, null, $meta);
        }

        return $onboarding->fresh(['vendor']);
    }

    /** Audit a Kickoff PDF interaction (viewed / downloaded / printed). */
    public function logKickoffEvent(PurchaseOnboarding $onboarding, string $event, User $actor, array $meta = []): void
    {
        $action = ['viewed' => 'Kickoff PDF Viewed', 'downloaded' => 'Kickoff PDF Downloaded', 'printed' => 'Kickoff PDF Printed'][$event] ?? null;
        if ($action === null) {
            throw new BusinessException('Unknown kickoff event.');
        }
        $onboarding->recordAudit($action, $actor, null, $meta);
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
            try {
                \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($toEmail, $subject) {
                    $m->to($toEmail)->subject($subject);
                });
            } catch (\Throwable $e) {
                Log::channel('purchase')->warning('Notification mail error: '.$e->getMessage());
            }
        }

        Log::channel('purchase')->info("Notification logged for {$company} - Status: {$status}");
    }
}
