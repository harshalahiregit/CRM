<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseKickoffMom;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Purchase\PurchaseKickoffRepository;
use App\Services\Notifications\NotificationService;
use App\Support\Purchase\PurchaseKickoffStatus as Status;
use App\Support\Purchase\PurchaseMomApprovalStatus as MomStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The Purchase kickoff engine — schedule, participants, MOM editor + PDF, status
 * transitions, acknowledgement, attendance and reminders. Purchase-owned:
 * reads/writes only purchase_kickoff_meetings / purchase_kickoff_participants /
 * purchase_kickoff_mom, and the private `purchase_kickoff_docs` disk. Never
 * touches the shared/TPV kickoff tables or services. Reuses only generic infra
 * (DomPDF, NotificationService, Storage, BaseRepository).
 */
class PurchaseKickoffService
{
    private const DISK = 'purchase_kickoff_docs';

    public function __construct(
        private PurchaseKickoffRepository $repo,
        private NotificationService $notifications,
    ) {
    }

    public function list(int $tenantId, array $filters)
    {
        return $this->repo->filtered($tenantId, $filters);
    }

    public function stats(int $tenantId): array
    {
        return $this->repo->stats($tenantId);
    }

    /** Tenant-guarded fetch — 404 rather than leak existence across tenants. */
    public function find(int $id, int $tenantId): PurchaseKickoffMeeting
    {
        $meeting = $this->repo->findForTenant($id, $tenantId);

        if (! $meeting) {
            throw new BusinessException('Kickoff meeting not found.', 404);
        }

        return $meeting;
    }

    /** Schedule a kickoff against a Purchase vendor. */
    public function schedule(array $data, User $actor): PurchaseKickoffMeeting
    {
        $vendor = $this->resolveVendor($data['purchase_vendor_id'], $actor->tenant_id);

        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id'              => $actor->tenant_id,
            'created_by'             => $actor->id,
            'purchase_vendor_id'     => $vendor->id,
            'purchase_onboarding_id' => $data['purchase_onboarding_id'] ?? $this->onboardingIdFor($vendor),
            'title'                  => $data['title'] ?? $this->defaultTitle($vendor),
            'meeting_type'           => $data['meeting_type'] ?? \App\Support\Purchase\PurchaseMeetingTypeCatalog::DEFAULT,
            'reference'              => $data['reference'] ?? null,
            'agenda'                 => $data['agenda'] ?? null,
            'status'                 => Status::SCHEDULED,
            'scheduled_at'           => $data['scheduled_at'] ?? null,
            'duration_minutes'       => $data['duration_minutes'] ?? null,
            'mode'                   => $data['mode'] ?? null,
            'location'               => $data['location'] ?? null,
            'meeting_platform'       => $data['meeting_platform'] ?? null,
            'meeting_link'           => $data['meeting_link'] ?? null,
            'meeting_id'             => $data['meeting_id'] ?? null,
            'meeting_passcode'       => $data['meeting_passcode'] ?? null,
            'meeting_host_link'      => $data['meeting_host_link'] ?? null,
        ]);

        $this->syncOnboardingPointer($meeting);

        if (! empty($data['participants'])) {
            $this->replaceParticipants($meeting, $data['participants'], $actor->tenant_id);
        }

        $meeting->recordAudit('created', $actor, "Kickoff '{$meeting->title}' scheduled", [
            'vendor' => $vendor->company_name,
        ]);
        Log::channel('purchase')->info('Purchase kickoff scheduled', [
            'meeting_id' => $meeting->id, 'tenant_id' => $actor->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** Edit an open meeting's details (status goes through transition()). */
    public function update(PurchaseKickoffMeeting $meeting, array $data, User $actor): PurchaseKickoffMeeting
    {
        if (Status::isClosed($meeting->status)) {
            throw new BusinessException('A '.Status::label($meeting->status).' meeting can no longer be edited. Reopen it first.');
        }

        $meeting->update(array_filter([
            'title'             => $data['title'] ?? null,
            'meeting_type'      => $data['meeting_type'] ?? null,
            'reference'         => $data['reference'] ?? null,
            'agenda'            => $data['agenda'] ?? null,
            'scheduled_at'      => $data['scheduled_at'] ?? null,
            'duration_minutes'  => $data['duration_minutes'] ?? null,
            'mode'              => $data['mode'] ?? null,
            'location'          => $data['location'] ?? null,
            'meeting_platform'  => $data['meeting_platform'] ?? null,
            'meeting_link'      => $data['meeting_link'] ?? null,
            'meeting_id'        => $data['meeting_id'] ?? null,
            'meeting_passcode'  => $data['meeting_passcode'] ?? null,
            'meeting_host_link' => $data['meeting_host_link'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('participants', $data)) {
            $this->replaceParticipants($meeting, $data['participants'] ?? [], $actor->tenant_id);
        }

        $meeting->recordAudit('updated', $actor, 'Meeting details updated');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** Move the meeting along its lifecycle (guarded by the transition map). */
    public function transition(PurchaseKickoffMeeting $meeting, string $to, array $data, User $actor): PurchaseKickoffMeeting
    {
        if (! Status::canTransition($meeting->status, $to)) {
            throw new BusinessException(
                'Cannot move a '.Status::label($meeting->status).' meeting to '.Status::label($to).'.'
            );
        }

        $changes = ['status' => $to];

        if ($to === Status::DELAYED) {
            if (trim((string) ($data['delay_reason'] ?? '')) === '') {
                throw new BusinessException('A delay needs a reason — say why the meeting slipped.');
            }
            $changes['original_scheduled_at'] = $meeting->original_scheduled_at ?? $meeting->scheduled_at;
            $changes['delay_reason']          = $data['delay_reason'];
            if (! empty($data['scheduled_at'])) {
                $changes['scheduled_at'] = $data['scheduled_at'];
            }
        }

        if ($to === Status::SCHEDULED && ! empty($data['scheduled_at'])) {
            $changes['scheduled_at'] = $data['scheduled_at'];
        }

        if ($to === Status::COMPLETED) {
            $changes['completed_at'] = now();
            if (array_key_exists('minutes', $data)) {
                $changes['minutes'] = $data['minutes'];
            }
        }

        $meeting->update($changes);

        $verb = ['Delayed' => 'delayed', 'Completed' => 'completed', 'Cancelled' => 'cancelled', 'Scheduled' => 'rescheduled'][$to] ?? 'updated';
        $meeting->recordAudit($verb, $actor, ucfirst($verb).($data['delay_reason'] ?? null ? ": {$data['delay_reason']}" : ''));
        Log::channel('purchase')->info('Purchase kickoff '.$verb, [
            'meeting_id' => $meeting->id, 'actor_id' => $actor->id, 'status' => $to,
        ]);

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** Attach an uploaded Minutes-of-Meeting document as the current MOM. */
    public function uploadMom(PurchaseKickoffMeeting $meeting, UploadedFile $file, User $actor): PurchaseKickoffMeeting
    {
        $name = 'mom-'.Str::random(12).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs("tenant-{$meeting->tenant_id}/meeting-{$meeting->id}", $name, self::DISK);

        $this->storeMom($meeting, $path, 'uploaded', $actor, $file->getClientOriginalName());
        $meeting->recordAudit('mom_uploaded', $actor, 'Minutes of meeting uploaded');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Build the Minutes-of-Meeting PDF from existing meeting data only. Replaces
     * the previous current MOM so there is only ever one current document.
     */
    public function generateMom(PurchaseKickoffMeeting $meeting, User $actor): PurchaseKickoffMeeting
    {
        $meeting->loadMissing('participants', 'vendor', 'creator');

        $pdf = Pdf::loadView('pdf.purchase_kickoff_mom', [
            'meeting'     => $meeting,
            'tenant'      => Tenant::find($meeting->tenant_id),
            'vendorName'  => $meeting->vendor?->company_name,
            'generatedBy' => $actor->name,
            'generatedAt' => now(),
        ])->setPaper('a4');

        $path = "tenant-{$meeting->tenant_id}/meeting-{$meeting->id}/mom-".Str::random(12).'.pdf';
        Storage::disk(self::DISK)->put($path, $pdf->output());

        $this->storeMom($meeting, $path, 'generated', $actor);
        $meeting->recordAudit('mom_generated', $actor, 'Minutes of meeting PDF generated');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** The current MOM file for download (or null if none). */
    public function currentMomFile(PurchaseKickoffMeeting $meeting): ?array
    {
        $mom = $meeting->currentMom()->first();
        if (! $mom || ! Storage::disk(self::DISK)->exists($mom->file_path)) {
            return null;
        }

        $path = Storage::disk(self::DISK)->path($mom->file_path);

        return [
            'path'     => $path,
            'filename' => $mom->original_name ?: 'purchase-kickoff-mom.pdf',
            'mime'     => mime_content_type($path) ?: 'application/pdf',
        ];
    }

    /**
     * Submit the draft minutes into the approval workflow (Draft → Pending
     * Organizer). Requires the meeting to be Completed; generates the MOM PDF if
     * one doesn't exist yet so approvers have something to review.
     */
    public function submitMomForApproval(PurchaseKickoffMeeting $meeting, User $actor): PurchaseKickoffMeeting
    {
        if ($meeting->status !== Status::COMPLETED) {
            throw new BusinessException('Complete the meeting before submitting its minutes for approval.');
        }
        if (! MomStatus::canTransition($meeting->mom_status, MomStatus::PENDING)) {
            throw new BusinessException('These minutes are '.MomStatus::label($meeting->mom_status).' and cannot be submitted for approval.');
        }

        // Ensure there is a document to review; a generation failure is not fatal.
        if (! $meeting->currentMom()->exists()) {
            try {
                $this->generateMom($meeting, $actor);
            } catch (\Throwable $e) {
                Log::channel('purchase')->warning('Purchase MOM auto-generate on submit failed', [
                    'meeting_id' => $meeting->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $meeting->update([
            'mom_status'        => MomStatus::PENDING,
            'mom_submitted_at'  => now(),
            'mom_submitted_by'  => $actor->id,
            'mom_approval_note' => null,
        ]);
        $meeting->recordAudit('mom_submitted', $actor, 'Minutes submitted for approval');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Approve or return the minutes. `approve` steps the two-level chain
     * (Organizer → Chairperson → Approved); `return` sends them back to Draft
     * with a mandatory reason and clears the approval stamps.
     */
    public function decideMom(PurchaseKickoffMeeting $meeting, string $decision, ?string $note, User $actor): PurchaseKickoffMeeting
    {
        if (! in_array($meeting->mom_status, [MomStatus::PENDING, MomStatus::PENDING_CHAIR], true)) {
            throw new BusinessException('Only minutes awaiting approval can be approved or returned.');
        }

        if ($decision === 'return') {
            if (trim((string) $note) === '') {
                throw new BusinessException('Returning minutes for revision needs a reason.');
            }
            $meeting->update([
                'mom_status'                => MomStatus::DRAFT,
                'mom_approval_note'         => $note,
                'mom_organizer_approved_at' => null,
                'mom_organizer_approved_by' => null,
                'mom_approved_at'           => null,
                'mom_approved_by'           => null,
            ]);
            $meeting->recordAudit('mom_returned', $actor, 'Minutes returned for revision: '.$note);

            return $this->find($meeting->id, $actor->tenant_id);
        }

        if ($decision !== 'approve') {
            throw new BusinessException('Unknown MOM decision.');
        }

        if ($meeting->mom_status === MomStatus::PENDING) {
            $meeting->update([
                'mom_status'                => MomStatus::PENDING_CHAIR,
                'mom_organizer_approved_at' => now(),
                'mom_organizer_approved_by' => $actor->id,
                'mom_approval_note'         => $note ?: null,
            ]);
            $meeting->recordAudit('mom_organizer_approved', $actor, 'Minutes approved by organizer, awaiting chairperson');
        } else { // PENDING_CHAIR → Approved (final)
            $meeting->update([
                'mom_status'        => MomStatus::APPROVED,
                'mom_approved_at'   => now(),
                'mom_approved_by'   => $actor->id,
                'mom_approval_note' => $note ?: null,
            ]);
            $meeting->recordAudit('mom_approved', $actor, 'Minutes given final approval');
        }

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** First-view stamp for approved/distributed minutes (silent). */
    public function markMomViewed(PurchaseKickoffMeeting $meeting): void
    {
        if (! $meeting->mom_viewed_at) {
            $meeting->forceFill(['mom_viewed_at' => now()])->saveQuietly();
        }
    }

    /**
     * Pull approved/distributed minutes back to Draft for revision. Clears the
     * approval stamps (distribution stamps are kept as history); the minutes must
     * be re-approved before they can be distributed again.
     */
    public function reviseMom(PurchaseKickoffMeeting $meeting, User $actor): PurchaseKickoffMeeting
    {
        if (! in_array($meeting->mom_status, [MomStatus::APPROVED, MomStatus::DISTRIBUTED], true)) {
            throw new BusinessException('Only approved or distributed minutes can be pulled back for revision.');
        }

        $meeting->update([
            'mom_status'                => MomStatus::DRAFT,
            'mom_organizer_approved_at' => null,
            'mom_organizer_approved_by' => null,
            'mom_approved_at'           => null,
            'mom_approved_by'           => null,
        ]);
        $meeting->recordAudit('mom_revised', $actor, 'Approved minutes pulled back to Draft for revision');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** Publish the minutes for vendor acknowledgement — mints the public token. */
    public function publishForAck(PurchaseKickoffMeeting $meeting, User $actor): PurchaseKickoffMeeting
    {
        if ($meeting->status !== Status::COMPLETED) {
            throw new BusinessException('Complete the meeting before sending its minutes for acknowledgement.');
        }
        if (! $meeting->currentMom()->exists()) {
            throw new BusinessException('Generate or upload the MOM PDF before sending for acknowledgement.');
        }
        if (! MomStatus::isDistributable($meeting->mom_status)) {
            throw new BusinessException('The minutes must be approved before they can be sent to the vendor.');
        }
        if ($meeting->acknowledged_at) {
            throw new BusinessException('This meeting has already been acknowledged.');
        }

        $meeting->update([
            'ack_token'          => Str::random(48),
            'mom_status'         => MomStatus::DISTRIBUTED,
            'mom_distributed_at' => $meeting->mom_distributed_at ?? now(),
            'mom_distributed_by' => $meeting->mom_distributed_by ?? $actor->id,
        ]);
        $this->sendMomNotifications($meeting);
        $meeting->recordAudit('mom_published', $actor, 'Minutes sent to the vendor for acknowledgement');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    public function resolveByToken(string $token): PurchaseKickoffMeeting
    {
        $meeting = PurchaseKickoffMeeting::where('ack_token', $token)->with(['participants', 'vendor'])->first();

        if (! $meeting) {
            throw new BusinessException('This acknowledgement link is not valid. It may already have been used.', 404);
        }

        return $meeting;
    }

    public function acknowledge(PurchaseKickoffMeeting $meeting, array $data, array $meta): PurchaseKickoffMeeting
    {
        if ($meeting->acknowledged_at) {
            throw new BusinessException('These minutes have already been acknowledged.');
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new BusinessException('Please enter your name to acknowledge the minutes.');
        }

        $meeting->update([
            'acknowledged_at'      => now(),
            'acknowledged_by_name' => $data['name'],
            'acknowledged_ip'      => $meta['ip'] ?? null,
            'ack_token'            => null,
        ]);

        $meeting->recordAudit('acknowledged', null, "Minutes acknowledged by {$data['name']}", [
            'ip' => $meta['ip'] ?? null,
        ]);
        Log::channel('purchase')->info('Purchase kickoff minutes acknowledged', [
            'meeting_id' => $meeting->id, 'by' => $data['name'], 'ip' => $meta['ip'] ?? null,
        ]);

        return $meeting->fresh();
    }

    public function delete(PurchaseKickoffMeeting $meeting, User $actor): void
    {
        $meeting->recordAudit('deleted', $actor, "Kickoff '{$meeting->title}' deleted");
        $meeting->delete();
    }

    /** Mark who actually turned up. Unknown ids are ignored, not errored. */
    public function markAttendance(PurchaseKickoffMeeting $meeting, array $rows, User $actor): PurchaseKickoffMeeting
    {
        $present = 0;
        $absent  = 0;

        foreach ($rows as $row) {
            $participant = $meeting->participants()->whereKey($row['id'])->first();
            if (! $participant) {
                continue;
            }
            $attended = ! empty($row['attended']);
            $participant->update(['attended' => $attended]);
            $attended ? $present++ : $absent++;
        }

        $meeting->recordAudit('attendance_marked', $actor, "Attendance updated: {$present} present, {$absent} absent");
        Log::channel('purchase')->info('Purchase kickoff attendance marked', [
            'meeting_id' => $meeting->id, 'actor_id' => $actor->id, 'present' => $present, 'absent' => $absent,
        ]);

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** Send a manual meeting reminder (email real; WhatsApp/SMS stubbed upstream). */
    public function sendReminder(PurchaseKickoffMeeting $meeting, User $actor): array
    {
        $meeting->loadMissing('participants', 'vendor');

        $vendorName = $meeting->vendor?->company_name;
        $when    = $meeting->scheduled_at ? $meeting->scheduled_at->format('d M Y, g:i A') : 'a date to be confirmed';
        $where   = $meeting->location ? " at {$meeting->location}" : '';
        $subject = "Reminder: {$meeting->title}";
        $body    = "This is a reminder for the kickoff meeting \"{$meeting->title}\""
            .($vendorName ? " with {$vendorName}" : '')
            .", scheduled for {$when}{$where}.";

        $email = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($meeting->participants as $participant) {
            $result = $this->notifications->email($participant->email, $subject, $body, [
                'purchase_kickoff_meeting_id' => $meeting->id,
            ]);
            $email[$result] = ($email[$result] ?? 0) + 1;
        }

        $phone    = $meeting->vendor?->phone;
        $whatsapp = $this->notifications->whatsapp($phone, $body, ['purchase_kickoff_meeting_id' => $meeting->id]);
        $sms      = $this->notifications->sms($phone, $body, ['purchase_kickoff_meeting_id' => $meeting->id]);

        $meeting->recordAudit('reminder_sent', $actor, "Reminder sent — email: {$email['sent']} sent, WhatsApp/SMS queued");

        return [
            'recipients' => $meeting->participants->count(),
            'email'      => $email,
            'whatsapp'   => $whatsapp,
            'sms'        => $sms,
        ];
    }

    /* ── internals ─────────────────────────────────────────────── */

    /** Persist a MOM row and flip the previous current one. */
    private function storeMom(PurchaseKickoffMeeting $meeting, string $path, string $source, User $actor, ?string $originalName = null): PurchaseKickoffMom
    {
        // Remove the prior current file so it isn't orphaned, then demote the row.
        $prev = $meeting->currentMom()->first();
        if ($prev) {
            if ($prev->file_path && Storage::disk(self::DISK)->exists($prev->file_path)) {
                Storage::disk(self::DISK)->delete($prev->file_path);
            }
            $meeting->momDocuments()->where('is_current', true)->update(['is_current' => false]);
        }

        return PurchaseKickoffMom::create([
            'tenant_id'                   => $meeting->tenant_id,
            'purchase_kickoff_meeting_id' => $meeting->id,
            'file_path'                   => $path,
            'original_name'               => $originalName,
            'source'                      => $source,
            'is_current'                  => true,
            'generated_by'                => $actor->id,
            'generated_at'                => now(),
        ]);
    }

    private function sendMomNotifications(PurchaseKickoffMeeting $meeting): void
    {
        $meeting->loadMissing(['participants', 'vendor']);

        $vendor = $meeting->vendor;
        $email  = $vendor?->email;
        $phone  = $vendor?->phone;

        if (! $email && $meeting->participants->count() > 0) {
            $email = $meeting->participants->firstWhere('email', '!=', null)?->email;
        }

        $subjectTitle = "Minutes of Meeting Ready: {$meeting->title}";
        $body = "The Minutes of Meeting (MOM) for \"{$meeting->title}\" have been published and are ready for your review and acknowledgement.\n\nPlease log into the Purchase Vendor Portal (Step 1 Onboarding) to view the document and record your acknowledgement.";

        if ($email) {
            $this->notifications->email($email, $subjectTitle, $body, ['purchase_kickoff_meeting_id' => $meeting->id]);
        }
        if ($phone) {
            $this->notifications->whatsapp($phone, $body, ['purchase_kickoff_meeting_id' => $meeting->id]);
        }
    }

    /** Rebuild the participant list from the payload (Purchase contacts verified). */
    private function replaceParticipants(PurchaseKickoffMeeting $meeting, array $participants, int $tenantId): void
    {
        $meeting->participants()->delete();

        foreach ($participants as $p) {
            $contact = null;
            if (! empty($p['purchase_contact_id'])) {
                $contact = PurchaseContact::forTenant($tenantId)->find($p['purchase_contact_id']);
                if (! $contact) {
                    throw new BusinessException('One of the selected vendor contacts does not exist.');
                }
            }

            PurchaseKickoffParticipant::create([
                'tenant_id'                   => $tenantId,
                'purchase_kickoff_meeting_id' => $meeting->id,
                'purchase_contact_id'         => $contact?->id,
                'name'                        => $contact?->full_name ?? ($p['name'] ?? 'Unnamed participant'),
                'email'                       => $contact?->email ?? ($p['email'] ?? null),
                'organisation'                => $p['organisation'] ?? null,
                'role'                        => $p['role'] ?? null,
                'attended'                    => ! empty($p['attended']),
            ]);
        }
    }

    private function resolveVendor(int $vendorId, int $tenantId): PurchaseVendor
    {
        $vendor = PurchaseVendor::forTenant($tenantId)->find($vendorId);
        if (! $vendor) {
            throw new BusinessException('Purchase vendor not found.', 404);
        }

        return $vendor;
    }

    /** Latest Purchase onboarding id for a vendor (back-pointer convenience). */
    private function onboardingIdFor(PurchaseVendor $vendor): ?int
    {
        return PurchaseOnboarding::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->latest()
            ->value('id');
    }

    /** Point the vendor's Purchase onboarding at this meeting (if not already set). */
    private function syncOnboardingPointer(PurchaseKickoffMeeting $meeting): void
    {
        $onboarding = $meeting->purchase_onboarding_id
            ? PurchaseOnboarding::forTenant($meeting->tenant_id)->find($meeting->purchase_onboarding_id)
            : PurchaseOnboarding::forTenant($meeting->tenant_id)->where('purchase_vendor_id', $meeting->purchase_vendor_id)->latest()->first();

        if ($onboarding && ! $onboarding->kickoff_meeting_id) {
            $onboarding->update(['kickoff_meeting_id' => $meeting->id]);
        }
    }

    private function defaultTitle(PurchaseVendor $vendor): string
    {
        return $vendor->company_name ? "Kickoff — {$vendor->company_name}" : 'Kickoff meeting';
    }
}
