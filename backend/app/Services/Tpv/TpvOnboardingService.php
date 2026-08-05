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

        return $query->latest()->get();
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

        if (! $meeting->acknowledged_at) {
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
    public function approve(TpvOnboarding $onboarding, User $actor, ?string $remarks = null): TpvOnboarding
    {
        $registrationNumber = $onboarding->registration_number
            ?: $this->registrationNumbers->generate($onboarding->tenant_id);

        $onboarding->update([
            'status'              => Status::APPROVED,
            'approved_at'         => now(),
            'approved_by'         => $actor->id,
            'remarks'             => $remarks ?? $onboarding->remarks,
            'registration_number' => $registrationNumber,
        ]);
        $onboarding->vendor->update([
            'registration_number' => $registrationNumber,
        ]);

        $onboarding->recordAudit('Onboarding Approved', $actor, $remarks, [
            'to' => Status::APPROVED, 'registration_number' => $registrationNumber,
        ]);

        Log::channel('tpv')->info('TPV onboarding approved', [
            'onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id,
            'actor_id' => $actor->id, 'registration_number' => $registrationNumber,
        ]);

        $this->dispatchStatusNotification($onboarding, Status::APPROVED, $remarks);

        return $onboarding;
    }

    /** Decline the onboarding outright, with a mandatory reason. */
    public function reject(TpvOnboarding $onboarding, User $actor, string $remarks): TpvOnboarding
    {
        $onboarding->update(['status' => Status::REJECTED, 'remarks' => $remarks]);
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
                "Access Portal: " . url('/vendor-portal/login') . "\n\n" .
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
            try {
                \Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($toEmail, $subject) {
                    $message->to($toEmail)->subject($subject);
                });
            } catch (\Throwable $e) {
                Log::channel('tpv')->warning("Notification mail error: " . $e->getMessage());
            }
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
