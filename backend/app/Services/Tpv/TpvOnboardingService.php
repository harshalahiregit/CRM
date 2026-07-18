<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
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
    public function __construct(private VendorDocumentService $documentService)
    {
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

        return $query->latest()->get();
    }

    /** Start the 6-step wizard for a vendor. One onboarding per vendor. */
    public function create(array $data, int $tenantId): TpvOnboarding
    {
        $vendor = Vendor::forTenant($tenantId)->find($data['vendor_id']);

        if (! $vendor) {
            throw new BusinessException('Vendor not found.', 404);
        }
        if (TpvOnboarding::forTenant($tenantId)->where('vendor_id', $vendor->id)->exists()) {
            throw new BusinessException("Vendor {$vendor->vendor_code} already has an onboarding in progress.");
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

        $onboarding->update(['current_step' => $step]);
        $onboarding->recordAudit('Onboarding Step Changed', $actor, null, ['step' => $step]);

        return $onboarding;
    }

    /** Step 2 — persist the TPV-specific company/contact profile. */
    public function saveProfile(TpvOnboarding $onboarding, array $profile, User $actor): TpvOnboarding
    {
        if (! $onboarding->isEditable()) {
            throw new BusinessException('This onboarding is no longer editable.');
        }

        $onboarding->update([
            'profile'      => array_merge($onboarding->profile ?? [], $profile),
            'current_step' => max($onboarding->current_step, 2),
        ]);
        $onboarding->recordAudit('Profile Saved', $actor);

        Log::channel('tpv')->info('TPV onboarding profile saved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding->fresh(['vendor']);
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
                ['step' => 1, 'key' => 'kickoff',      'label' => 'Kickoff',         'complete' => true,         'detail' => 'Pre-onboarding briefing'],
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
    public function submit(TpvOnboarding $onboarding, User $actor): TpvOnboarding
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
            'status'       => Status::SUBMITTED,
            'current_step' => Status::TOTAL_STEPS,
            'submitted_at' => now(),
        ]);
        $onboarding->vendor->update(['status' => VendorStatus::PENDING_APPROVAL]);

        $onboarding->recordAudit('Onboarding Submitted', $actor, null, ['to' => Status::SUBMITTED]);

        Log::channel('tpv')->info('TPV onboarding submitted', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding;
    }

    /**
     * Admin approval — activates the vendor for site access.
     *
     * The branded HSSE Work-Start Letter PDF is not generated yet; the column
     * exists but stays null until that's built.
     */
    public function approve(TpvOnboarding $onboarding, User $actor, ?string $remarks = null): TpvOnboarding
    {
        if (! in_array($onboarding->status, [Status::SUBMITTED, Status::UNDER_REVIEW], true)) {
            throw new BusinessException('Only a submitted onboarding can be approved.');
        }

        $onboarding->update([
            'status'      => Status::APPROVED,
            'approved_at' => now(),
            'approved_by' => $actor->id,
            'remarks'     => $remarks ?? $onboarding->remarks,
        ]);
        $onboarding->vendor->update([
            'status'      => VendorStatus::ACTIVE,
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);

        $onboarding->recordAudit('Onboarding Approved', $actor, $remarks, ['to' => Status::APPROVED]);

        Log::channel('tpv')->info('TPV onboarding approved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $onboarding;
    }

    /** Send the wizard back to the vendor for correction. */
    public function requestResubmit(TpvOnboarding $onboarding, User $actor, string $remarks): TpvOnboarding
    {
        if (! in_array($onboarding->status, [Status::SUBMITTED, Status::UNDER_REVIEW], true)) {
            throw new BusinessException('Only a submitted onboarding can be sent back.');
        }

        $onboarding->update(['status' => Status::RESUBMIT_REQUIRED, 'remarks' => $remarks]);
        $onboarding->recordAudit('Resubmission Requested', $actor, $remarks, ['to' => Status::RESUBMIT_REQUIRED]);

        Log::channel('tpv')->info('TPV onboarding resubmission requested', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
        ]);

        return $onboarding;
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
