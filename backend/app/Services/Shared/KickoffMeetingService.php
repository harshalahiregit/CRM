<?php

namespace App\Services\Shared;

use App\Contracts\ProjectDirectoryContract;
use App\Exceptions\BusinessException;
use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMeetingSubject;
use App\Models\Shared\KickoffMomItem;
use App\Models\Shared\MeetingAgendaItem;
use App\Models\Shared\MeetingDecision;
use App\Models\Shared\MeetingDistribution;
use App\Models\Shared\MeetingIssue;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorContact;
use App\Repositories\Shared\KickoffMeetingRepository;
use App\Services\Notifications\NotificationService;
use App\Services\Task\TaskService;
use App\Services\Tpv\IncidentService;
use App\Services\Tpv\TpvApprovalService;
use App\Services\Tpv\TpvCapaService;
use App\Services\Tpv\TpvNcrService;
use App\Support\FrontendUrl;
use App\Support\Shared\KickoffStatus as Status;
use App\Support\Shared\KickoffSubject;
use App\Support\Shared\MeetingIssueStatus;
use App\Support\Shared\MeetingTypeCatalog;
use App\Support\Shared\MomActionStatus;
use App\Support\Shared\MomApprovalStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KickoffMeetingService
{
    private const DISK = 'kickoff_docs';

    public function __construct(
        private KickoffMeetingRepository $repo,
        private NotificationService $notifications,
        private MeetingInviteService $invites,
    ) {}

    public function list(int $tenantId, array $filters)
    {
        return $this->repo->filtered($tenantId, $filters);
    }

    public function stats(int $tenantId): array
    {
        return $this->repo->stats($tenantId);
    }

    /**
     * The Meetings dashboard aggregate (Meeting.docx §14): today / upcoming,
     * pending & overdue MOM, open & overdue actions, decisions, meetings by type,
     * and the action-closure effectiveness rate.
     */
    public function dashboard(int $tenantId): array
    {
        $meetings = KickoffMeeting::forTenant($tenantId);

        $today = (clone $meetings)->open()->whereDate('scheduled_at', now()->toDateString())->count();
        $upcoming = (clone $meetings)->open()->whereNotNull('scheduled_at')->where('scheduled_at', '>', now())->count();

        // MOM not yet distributed on a completed meeting; overdue if completed > 3 days ago.
        $pendingMomBase = (clone $meetings)->where('status', Status::COMPLETED)
            ->where(fn ($q) => $q->whereNull('mom_status')->orWhere('mom_status', '!=', MomApprovalStatus::DISTRIBUTED));
        $pendingMom = (clone $pendingMomBase)->count();
        $overdueMom = (clone $pendingMomBase)->whereNotNull('completed_at')
            ->where('completed_at', '<', now()->subDays(3))->count();

        // Action effectiveness across the tenant.
        $actions = KickoffMomItem::where('tenant_id', $tenantId);
        $totalActions = (clone $actions)->count();
        $closedActions = (clone $actions)->where('status', MomActionStatus::CLOSED)->count();
        $openActions = (clone $actions)->whereIn('status', MomActionStatus::OPEN_STATES)->count();
        $overdueActions = (clone $actions)->whereIn('status', MomActionStatus::OPEN_STATES)
            ->whereNotNull('target_date')->whereDate('target_date', '<', now())->count();
        $closureRate = $totalActions > 0 ? (int) round($closedActions / $totalActions * 100) : 0;

        // Decisions still in force (Active) and issues still open.
        $decisionsActive = MeetingDecision::where('tenant_id', $tenantId)->where('status', 'Active')->count();
        $openIssues = MeetingIssue::where('tenant_id', $tenantId)
            ->whereIn('status', MeetingIssueStatus::OPEN_STATES)->count();

        // Meetings by type + by status.
        $catalog = app(MeetingTypeCatalog::class);
        $byType = (clone $meetings)->get(['meeting_type'])
            ->groupBy('meeting_type')
            ->map(fn ($g, $type) => [
                'type' => $type,
                'label' => $catalog->label($tenantId, $type),
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->all();

        // Meetings by project (§14) — soft project link resolved via the contract.
        $projDir = app(ProjectDirectoryContract::class);
        $byProject = (clone $meetings)->whereNotNull('project_id')->get(['project_id'])
            ->groupBy('project_id')
            ->map(fn ($g, $pid) => [
                'project_id' => (int) $pid,
                'label' => $projDir->labelFor((int) $pid, $tenantId) ?? ('Project #'.$pid),
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->all();

        // Meetings by vendor/subject (§14) — resolves vendor + onboarding subjects
        // to their display name. Dashboard scale, so eager-loading the morph is fine.
        $byVendor = (clone $meetings)->whereNotNull('kickoffable_id')
            ->with('kickoffable')->get(['id', 'kickoffable_type', 'kickoffable_id'])
            ->groupBy(fn ($m) => $m->kickoffable_type.'#'.$m->kickoffable_id)
            ->map(fn ($g) => [
                'name' => KickoffSubject::nameOf($g->first()->kickoffable) ?? 'Unknown',
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->all();

        return [
            'total' => (clone $meetings)->count(),
            'today' => $today,
            'upcoming' => $upcoming,
            'scheduled' => (clone $meetings)->where('status', Status::SCHEDULED)->count(),
            'delayed' => (clone $meetings)->where('status', Status::DELAYED)->count(),
            'completed' => (clone $meetings)->where('status', Status::COMPLETED)->count(),
            'pending_mom' => $pendingMom,
            'overdue_mom' => $overdueMom,
            'awaiting_ack' => (clone $meetings)->where('status', Status::COMPLETED)->whereNull('acknowledged_at')->count(),
            'total_actions' => $totalActions,
            'open_actions' => $openActions,
            'overdue_actions' => $overdueActions,
            'closed_actions' => $closedActions,
            'closure_rate' => $closureRate,
            'decisions_active' => $decisionsActive,
            'open_issues' => $openIssues,
            'by_type' => $byType,
            'by_project' => $byProject,
            'by_vendor' => $byVendor,
        ];
    }

    /** Tenant-guarded fetch — 404 rather than leak existence across tenants. */
    public function find(int $id, int $tenantId): KickoffMeeting
    {
        $meeting = $this->repo->findForTenant($id, $tenantId);

        if (! $meeting) {
            throw new BusinessException('Kickoff meeting not found.', 404);
        }

        return $meeting;
    }

    /** Schedule a meeting against a subject (or standalone). */
    public function schedule(array $data, User $actor): KickoffMeeting
    {
        $subject = $this->resolveSubject($data['subject_type'] ?? null, $data['subject_id'] ?? null, $actor->tenant_id);

        $meeting = KickoffMeeting::create([
            'tenant_id' => $actor->tenant_id,
            'created_by' => $actor->id,
            'kickoffable_type' => $subject ? $subject::class : null,
            'kickoffable_id' => $subject?->id,
            'meeting_type' => $data['meeting_type'] ?? config('meetings.default_type', 'kickoff'),
            'title' => $data['title'] ?? $this->defaultTitle($subject),
            'reference' => $data['reference'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'status' => Status::SCHEDULED,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'priority' => $data['priority'] ?? null,
            'confidentiality' => $data['confidentiality'] ?? null,
            'chairperson' => $data['chairperson'] ?? null,
            'organizer' => $data['organizer'] ?? null,
            'coordinator' => $data['coordinator'] ?? null,
            'department' => $data['department'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'work_package' => $data['work_package'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'mode' => $data['mode'] ?? null,
            'planned_date' => $data['planned_date'] ?? null,
            'city' => $data['city'] ?? null,
            'venue' => $data['venue'] ?? $data['location_detail'] ?? null,
            'address' => $data['address'] ?? null,
            'location' => $this->composeLocation($data),
        ]);

        // Convenience back-pointer: when the subject is an onboarding, fill its
        // legacy kickoff_meeting_id so code reading the FK sees the latest meeting.
        $this->syncOnboardingPointer($subject, $meeting->id);

        // Additional vendors, if the caller sent any. `subject_id` above remains
        // the primary and stays on kickoffable_*, so nothing that queries those
        // two columns changes behaviour.
        $this->syncSubjects($meeting, $subject, $data['subject_ids'] ?? [], $actor->tenant_id);

        if (! empty($data['attendees'])) {
            $this->replaceAttendees($meeting, $data['attendees'], $actor->tenant_id);
        }

        // Agenda first — it returns the client-key → id map the actions and
        // decisions link against (Meeting.docx §7). null = agenda not in this
        // submission, so the links below are left untouched.
        $agendaMap = array_key_exists('agenda_items', $data)
            ? $this->replaceAgendaItems($meeting, $data['agenda_items'] ?? [], $actor->tenant_id)
            : null;

        if (array_key_exists('mom_items', $data)) {
            $this->replaceMomItems($meeting, $data['mom_items'] ?? [], $actor->tenant_id, $agendaMap);
        }

        if (array_key_exists('decisions', $data)) {
            $this->syncDecisions($meeting, $data['decisions'] ?? [], $actor->tenant_id, $agendaMap);
        }

        if (array_key_exists('issues', $data)) {
            $this->syncIssues($meeting, $data['issues'] ?? [], $actor->tenant_id);
        }

        $meeting->recordAudit('created', $actor, "Kickoff '{$meeting->title}' scheduled", [
            'subject' => $subject ? KickoffSubject::nameOf($subject) : null,
        ]);
        Log::channel('tpv')->info('Kickoff scheduled', [
            'meeting_id' => $meeting->id, 'tenant_id' => $actor->tenant_id, 'actor_id' => $actor->id,
        ]);

        // Meeting.docx §1 puts "Send Invitation" in the lifecycle immediately
        // after scheduling. Nobody was ever told a meeting existed; the roster
        // only found out if a coordinator remembered to press Remind.
        //
        // Only when there is a date to invite people TO — a meeting drafted
        // without one is not ready to go out, and $data['send_invitations']
        // === false lets a caller draft one deliberately.
        if ($meeting->scheduled_at && ($data['send_invitations'] ?? true)) {
            $this->invites->sendInvitations($meeting->fresh(['attendees', 'agendaItems']), $actor);
        }

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Send (or re-send) the invitation on demand (Meeting.docx §1).
     *
     * Separate from the automatic send at scheduling because the roster and the
     * time both change after a meeting is first created, and the people added
     * afterwards must still be told.
     */
    public function sendInvitations(KickoffMeeting $meeting, User $actor): array
    {
        if (! $meeting->scheduled_at) {
            throw new BusinessException('Set the meeting date and time before sending invitations.');
        }
        if (Status::isClosed($meeting->status)) {
            throw new BusinessException('A '.Status::label($meeting->status).' meeting cannot send invitations.');
        }

        return $this->invites->sendInvitations($meeting, $actor);
    }

    /** Edit an open meeting's details (not its status — that goes through the transitions). */
    public function update(KickoffMeeting $meeting, array $data, User $actor): KickoffMeeting
    {
        if (Status::isClosed($meeting->status)) {
            throw new BusinessException('A '.Status::label($meeting->status).' meeting can no longer be edited. Reopen it first.');
        }

        $meeting->update(array_filter([
            'title' => $data['title'] ?? null,
            'meeting_type' => $data['meeting_type'] ?? null,
            'reference' => $data['reference'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'priority' => $data['priority'] ?? null,
            'confidentiality' => $data['confidentiality'] ?? null,
            'chairperson' => $data['chairperson'] ?? null,
            'organizer' => $data['organizer'] ?? null,
            'coordinator' => $data['coordinator'] ?? null,
            'department' => $data['department'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'work_package' => $data['work_package'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'mode' => $data['mode'] ?? null,
            'planned_date' => $data['planned_date'] ?? null,
            'city' => $data['city'] ?? null,
            'venue' => $data['venue'] ?? $data['location_detail'] ?? null,
            'address' => $data['address'] ?? null,
            // Recomposed from whichever parts were sent, falling back to what is
            // already stored, so editing only the city keeps the venue.
            'location' => $this->composeLocation($data, $meeting),
        ], fn ($v) => $v !== null));

        // Only touched when the caller actually sends the field, so an edit that
        // omits it leaves the vendor set alone rather than reducing it to one.
        if (array_key_exists('subject_ids', $data)) {
            $this->syncSubjects(
                $meeting, $meeting->kickoffable, $data['subject_ids'] ?? [], $actor->tenant_id
            );
        }

        if (array_key_exists('attendees', $data)) {
            $this->replaceAttendees($meeting, $data['attendees'] ?? [], $actor->tenant_id);
        }

        // Agenda first — its client-key → id map wires the action/decision links
        // (Meeting.docx §7). null when agenda is not part of this edit.
        $agendaMap = array_key_exists('agenda_items', $data)
            ? $this->replaceAgendaItems($meeting, $data['agenda_items'] ?? [], $actor->tenant_id)
            : null;

        if (array_key_exists('mom_items', $data)) {
            $this->replaceMomItems($meeting, $data['mom_items'] ?? [], $actor->tenant_id, $agendaMap);
        }

        if (array_key_exists('decisions', $data)) {
            $this->syncDecisions($meeting, $data['decisions'] ?? [], $actor->tenant_id, $agendaMap);
        }

        if (array_key_exists('issues', $data)) {
            $this->syncIssues($meeting, $data['issues'] ?? [], $actor->tenant_id);
        }

        $meeting->recordAudit('updated', $actor, 'Meeting details updated');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Move the meeting along its lifecycle. The target status drives what data is
     * required, and canTransition() refuses any move not on the map — a caller
     * cannot post Cancelled → Completed.
     */
    public function transition(KickoffMeeting $meeting, string $to, array $data, User $actor): KickoffMeeting
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
            // Preserve the first promised date so "how late" can be measured.
            $changes['original_scheduled_at'] = $meeting->original_scheduled_at ?? $meeting->scheduled_at;
            $changes['delay_reason'] = $data['delay_reason'];
            if (! empty($data['scheduled_at'])) {
                $changes['scheduled_at'] = $data['scheduled_at'];
            }
        }

        if ($to === Status::SCHEDULED && ! empty($data['scheduled_at'])) {
            $changes['scheduled_at'] = $data['scheduled_at'];
        }

        if ($to === Status::COMPLETED) {
            // A kickoff cannot have happened before it was scheduled to happen.
            // Guarded here rather than only in the UI so the API is the boundary.
            if (! $meeting->can_complete) {
                throw new BusinessException(
                    'This meeting is scheduled for '.$meeting->scheduled_at->format('d M Y H:i')
                    .' — it cannot be completed until then.'
                );
            }
            $changes['completed_at'] = now();
            if (array_key_exists('minutes', $data)) {
                $changes['minutes'] = $data['minutes'];
            }
        }

        $meeting->update($changes);

        $verb = ['Delayed' => 'delayed', 'Completed' => 'completed', 'Cancelled' => 'cancelled', 'Scheduled' => 'rescheduled'][$to] ?? 'updated';
        $meeting->recordAudit($verb, $actor, ucfirst($verb).($data['delay_reason'] ?? null ? ": {$data['delay_reason']}" : ''));
        Log::channel('tpv')->info('Kickoff '.$verb, [
            'meeting_id' => $meeting->id, 'actor_id' => $actor->id, 'status' => $to,
        ]);

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** Attach an uploaded Minutes-of-Meeting document (not generated — see migration). */
    public function uploadMom(KickoffMeeting $meeting, UploadedFile $file, User $actor): KickoffMeeting
    {
        // Replace the old file rather than orphan it on disk.
        if ($meeting->mom_path && Storage::disk(self::DISK)->exists($meeting->mom_path)) {
            Storage::disk(self::DISK)->delete($meeting->mom_path);
        }

        $name = 'mom-'.Str::random(12).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs("tenant-{$meeting->tenant_id}/meeting-{$meeting->id}", $name, self::DISK);

        $meeting->update(['mom_path' => $path]);
        $this->syncOnboardingPointer($meeting->kickoffable, $meeting->id);
        $meeting->recordAudit('mom_uploaded', $actor, 'Minutes of meeting uploaded');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /* ── MOM approval workflow (Meeting.docx — approve before distribute) ───── */

    /**
     * Submit the minutes for approval. Draft → Pending Approval. A PDF is the
     * thing being approved, so one is generated if none exists yet. Only a
     * Completed meeting has final minutes to approve.
     */
    public function submitMomForApproval(KickoffMeeting $meeting, User $actor): KickoffMeeting
    {
        if ($meeting->status !== Status::COMPLETED) {
            throw new BusinessException('Complete the meeting before submitting its minutes for approval.');
        }
        if (! MomApprovalStatus::canTransition($meeting->mom_status, MomApprovalStatus::PENDING)) {
            throw new BusinessException('These minutes are '.MomApprovalStatus::label($meeting->mom_status).' — they cannot be submitted for approval from here.');
        }

        // The reviewer approves a document, so generate one — but the approval of
        // the minutes' CONTENT must not be blocked if the PDF engine is momentarily
        // unavailable. A failed render is logged; the document can be regenerated or
        // uploaded before distribution (which does still require a file).
        if (! $meeting->mom_path) {
            try {
                $this->generateMom($meeting, $actor);
                $meeting->refresh();
            } catch (\Throwable $e) {
                Log::channel('tpv')->warning('MOM PDF generation failed on submit — proceeding without it', [
                    'meeting_id' => $meeting->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $meeting->update([
            'mom_status' => MomApprovalStatus::PENDING,
            'mom_submitted_at' => now(),
            'mom_submitted_by' => $actor->id,
            // Clear a stale return-reason from a previous round.
            'mom_approval_note' => null,
        ]);

        $meeting->recordAudit('mom_submitted', $actor, 'Minutes submitted for approval');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Decide on submitted minutes. 'approve' → Approved (a note is optional).
     * 'return' → back to Draft with a MANDATORY reason so the author knows what
     * to change. Only minutes that are Pending Approval can be decided.
     */
    public function decideMom(KickoffMeeting $meeting, string $decision, ?string $note, User $actor): KickoffMeeting
    {
        $status = $meeting->mom_status;
        if (! in_array($status, [MomApprovalStatus::PENDING, MomApprovalStatus::PENDING_CHAIR], true)) {
            throw new BusinessException('Only minutes awaiting approval can be approved or returned.');
        }

        $note = trim((string) $note);

        if ($decision === 'approve') {
            if ($status === MomApprovalStatus::PENDING) {
                // Level 1 — organizer approval hands off to the chairperson.
                $meeting->update([
                    'mom_status' => MomApprovalStatus::PENDING_CHAIR,
                    'mom_organizer_approved_at' => now(),
                    'mom_organizer_approved_by' => $actor->id,
                    'mom_approval_note' => $note !== '' ? $note : $meeting->mom_approval_note,
                ]);
                $meeting->recordAudit('mom_organizer_approved', $actor, 'Minutes approved by the organizer'.($note !== '' ? ": {$note}" : ''));
            } else {
                // Level 2 — chairperson gives final approval.
                $meeting->update([
                    'mom_status' => MomApprovalStatus::APPROVED,
                    'mom_approved_at' => now(),
                    'mom_approved_by' => $actor->id,
                    'mom_approval_note' => $note !== '' ? $note : $meeting->mom_approval_note,
                ]);
                $meeting->recordAudit('mom_approved', $actor, 'Minutes given final (chairperson) approval'.($note !== '' ? ": {$note}" : ''));
            }
        } elseif ($decision === 'return') {
            if ($note === '') {
                throw new BusinessException('Returning minutes needs a reason so the author knows what to revise.');
            }
            $meeting->update([
                'mom_status' => MomApprovalStatus::DRAFT,
                'mom_approval_note' => $note,
                'mom_organizer_approved_at' => null,
                'mom_organizer_approved_by' => null,
                'mom_approved_at' => null,
                'mom_approved_by' => null,
            ]);
            $meeting->recordAudit('mom_returned', $actor, "Minutes returned for revision: {$note}");
        } else {
            throw new BusinessException('Unknown approval decision.');
        }

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /** First-view stamp for the distribution Sent → Viewed → Acknowledged trail. */
    public function markMomViewed(KickoffMeeting $meeting, ?string $token = null): void
    {
        if ($meeting->mom_viewed_at === null) {
            $meeting->forceFill(['mom_viewed_at' => now()])->saveQuietly();
        }

        // Per-recipient view (Meeting.docx §13). The meeting-level stamp above is
        // kept as the headline "somebody has read it"; this says who.
        if ($token) {
            $row = MeetingDistribution::where('token', $token)
                ->where('kickoff_meeting_id', $meeting->id)->first();
            if ($row) {
                $this->invites->markViewed($row);
            }
        }
    }

    /**
     * The per-recipient delivery tracker for one meeting (Meeting.docx §13).
     *
     * Grouped by what was sent, so the detail page can show the invitation and
     * the minutes as two separate lists rather than one undifferentiated pile.
     *
     * @return array{invite: array<int,mixed>, mom: array<int,mixed>, totals: array<string,int>}
     */
    public function distributionTracker(KickoffMeeting $meeting): array
    {
        $rows = $meeting->distributions()->get();

        $shape = fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'email' => $r->email,
            'party' => $r->party,
            'channel' => $r->channel,
            'status' => $r->status,
            'state_label' => $r->state_label,
            'sent_at' => $r->sent_at,
            'viewed_at' => $r->viewed_at,
            'acknowledged_at' => $r->acknowledged_at,
        ];

        $mom = $rows->where('kind', MeetingDistribution::KIND_MOM);

        return [
            'invite' => $rows->where('kind', MeetingDistribution::KIND_INVITE)->map($shape)->values()->all(),
            'mom' => $mom->map($shape)->values()->all(),
            'totals' => [
                'sent' => $mom->where('status', MeetingDistribution::SENT)->count(),
                'viewed' => $mom->whereNotNull('viewed_at')->count(),
                'acknowledged' => $mom->whereNotNull('acknowledged_at')->count(),
                'no_address' => $mom->where('status', MeetingDistribution::SKIPPED)->count(),
            ],
        ];
    }

    /**
     * Pull approved (or already-distributed) minutes back to Draft so they can be
     * edited — they must then be re-submitted and re-approved. Distribution stamps
     * are left intact as the historical record of the previous issue.
     */
    public function reviseMom(KickoffMeeting $meeting, User $actor): KickoffMeeting
    {
        if (! MomApprovalStatus::canTransition($meeting->mom_status, MomApprovalStatus::DRAFT)) {
            throw new BusinessException('These minutes cannot be sent back for revision from '.MomApprovalStatus::label($meeting->mom_status).'.');
        }

        $meeting->update([
            'mom_status' => MomApprovalStatus::DRAFT,
            'mom_approved_at' => null,
            'mom_approved_by' => null,
            // Both sign-offs are void once the content reopens — the round starts over.
            'mom_organizer_approved_at' => null,
            'mom_organizer_approved_by' => null,
        ]);

        $meeting->recordAudit('mom_revised', $actor, 'Minutes reopened for revision');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Publish the minutes for vendor acknowledgement — mints the public token.
     * Only meaningful once the meeting is Completed; the vendor is acknowledging
     * what was agreed, and there is nothing to agree to before then.
     */
    public function publishForAck(KickoffMeeting $meeting, User $actor): KickoffMeeting
    {
        if ($meeting->status !== Status::COMPLETED) {
            throw new BusinessException('Complete the meeting before sending its minutes for acknowledgement.');
        }
        if (! $meeting->mom_path) {
            throw new BusinessException('Generate or upload the MOM PDF before sending for acknowledgement.');
        }
        // The approval gate: minutes cannot be distributed until they are approved.
        // Distribution IS this send, so this is where the gate belongs — no caller
        // (detail page, create page, or the public flow) can bypass it.
        if (! MomApprovalStatus::isDistributable($meeting->mom_status)) {
            throw new BusinessException('These minutes must be approved before they can be distributed.');
        }
        if ($meeting->acknowledged_at) {
            throw new BusinessException('This meeting has already been acknowledged.');
        }

        // The token is unchanged — the existing public ack flow keeps working.
        // The window is recorded alongside it so an expired acknowledgement is
        // distinguishable from one that was never asked for.
        $sentAt = now();
        $meeting->update([
            'ack_token' => Str::random(48),
            'acknowledgement_sent_at' => $sentAt,
            'acknowledgement_deadline' => $sentAt->copy()->addHours(KickoffMeeting::ACK_WINDOW_HOURS),
            'acknowledgement_status' => KickoffMeeting::ACK_PENDING,
            // Sending for acknowledgement IS distribution — record it on the MOM
            // lifecycle, stamping the distributor the first time only so a re-send
            // does not overwrite who originally issued the minutes.
            'mom_status' => MomApprovalStatus::DISTRIBUTED,
            'mom_distributed_at' => $meeting->mom_distributed_at ?? $sentAt,
            'mom_distributed_by' => $meeting->mom_distributed_by ?? $actor->id,
        ]);

        $this->sendMomNotifications($meeting);

        $meeting->recordAudit('mom_published', $actor, 'Minutes distributed to the vendor for acknowledgement'
            .' (valid '.KickoffMeeting::ACK_WINDOW_HOURS.'h)');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Send email + WhatsApp notifications to vendor when MOM is sent for acknowledgement.
     */
    private function sendMomNotifications(KickoffMeeting $meeting): void
    {
        $meeting->loadMissing(['attendees', 'kickoffable']);

        // A re-distribution replaces the previous ledger: those rows describe an
        // earlier send of an earlier document, and keeping them would double-count
        // the Sent/Viewed/Acknowledged tracker (Meeting.docx §13).
        $this->invites->resetMomLedger($meeting);

        $subject = $meeting->kickoffable;
        $vendor = null;
        $onboarding = null;

        if ($subject instanceof Vendor) {
            $vendor = $subject;
            $onboarding = TpvOnboarding::forTenant($meeting->tenant_id)->where('vendor_id', $vendor->id)->latest()->first();
        } elseif ($subject instanceof TpvOnboarding) {
            $onboarding = $subject;
            $vendor = $onboarding->vendor;
        }

        if ($onboarding && ! $onboarding->kickoff_meeting_id) {
            $onboarding->update(['kickoff_meeting_id' => $meeting->id]);
        }

        $email = $vendor?->email;
        $phone = $vendor?->phone;

        if (! $email && $meeting->attendees->count() > 0) {
            $email = $meeting->attendees->firstWhere('email', '!=', null)?->email;
        }

        $meetingDate = optional($meeting->scheduled_at)->format('l, j F Y');
        $dateSuffix = $meetingDate ? ' - '.optional($meeting->scheduled_at)->format('j M Y') : '';

        // Only the recipient who can actually acknowledge is told to. Promising
        // "Acknowledgment Required" to a vendor whose mail carries no acknowledge
        // button would be an instruction it cannot follow.
        $ackSubject = 'Kickoff Meeting Minutes - Acknowledgment Required'.$dateSuffix;
        $readSubject = 'Kickoff Meeting Minutes'.$dateSuffix;

        // The token IS the credential, so the link only exists while the window is
        // live. Previously it was minted and never sent, which left the public
        // one-click acknowledgement unreachable from the e-mail.
        $ackUrl = $meeting->ack_token && ! $meeting->acknowledged_at
            ? FrontendUrl::to('/kickoff/ack/'.$meeting->ack_token)
            : null;

        // Every vendor on the meeting gets its OWN read link, built from the
        // per-vendor token already minted into kickoff_meeting_subjects. A
        // secondary vendor could not read the minutes at all before this — the
        // mail only ever went to the vendor named on kickoffable_*.
        //
        // The ACKNOWLEDGE button is unchanged: it stays meeting-level and stays
        // with the primary, so no secondary is handed a credential that would let
        // it sign on everyone's behalf.
        $sent = [];

        foreach ($this->momRecipients($meeting, $vendor, $email) as $r) {
            $to = $r['email'];
            if (! $to || isset($sent[strtolower($to)])) {
                continue;
            }
            $sent[strtolower($to)] = true;

            $momUrl = $r['token'] ? FrontendUrl::to('/kickoff/mom/'.$r['token']) : null;
            $ackHere = $r['is_primary'] ? $ackUrl : null;

            $this->notifications->emailHtml(
                $to,
                $ackHere ? $ackSubject : $readSubject,
                $this->renderMomEmail($meeting, $r['vendor'], $ackHere, $meetingDate, $momUrl),
                ['category' => 'System', 'kickoff_meeting_id' => $meeting->id, 'vendor_id' => $r['vendor']?->id],
                $this->momPlainText($meeting, $r['vendor'], $ackHere, $meetingDate, $momUrl),
                $meeting->tenant_id,
            );

            $this->invites->recordMomRecipient(
                $meeting, MeetingDistribution::PARTY_VENDOR,
                $r['vendor']?->company_name ?? 'Vendor', $to,
            );
        }

        // The customer the meeting is for (Meeting.docx §13 lists Client as its
        // own distribution group). Resolved through CustomerServiceContract, so
        // the meetings engine still never queries the customers table.
        if ($client = $this->invites->clientRecipient($meeting)) {
            $to = $client['email'];
            if ($to && ! isset($sent[strtolower($to)])) {
                $sent[strtolower($to)] = true;
                $row = $this->invites->recordMomRecipient(
                    $meeting, MeetingDistribution::PARTY_CLIENT, $client['name'], $to,
                );
                $readUrl = FrontendUrl::to('/kickoff/mom/'.$row->token);
                $this->notifications->emailHtml(
                    $to, $readSubject,
                    $this->renderMomEmail($meeting, null, null, $meetingDate, $readUrl),
                    ['category' => 'System', 'kickoff_meeting_id' => $meeting->id],
                    $this->momPlainText($meeting, null, null, $meetingDate, $readUrl),
                    $meeting->tenant_id,
                );
            }
        }

        // Distribute a read copy to the internal side too (Meeting.docx §13 — the
        // minutes go to the organisation's own attendees, not only the vendor).
        // No acknowledge button and no public read token: internal staff read it in
        // the app; this is a courtesy notification that the minutes were issued.
        //
        // Every group §13 names gets a copy, not only the internal side: an
        // external consultant or a client representative on the roster was
        // previously left out of their own meeting's minutes entirely.
        foreach ($meeting->attendees as $a) {
            $party = $this->invites->partyFor($a);
            $to = $a->email;

            if ($to && isset($sent[strtolower($to)])) {
                continue;   // already covered as the vendor or the client
            }

            // A roster row with no address is still recorded — as 'skipped', so
            // the tracker says "no address" rather than implying delivery.
            $row = $this->invites->recordMomRecipient(
                $meeting, $party, $a->name, $to, $a->id, $a->user_id,
            );

            if ($to) {
                $sent[strtolower($to)] = true;
                $readUrl = FrontendUrl::to('/kickoff/mom/'.$row->token);
                $this->notifications->emailHtml(
                    $to,
                    $readSubject,
                    $this->renderMomEmail($meeting, null, null, $meetingDate, $readUrl),
                    ['category' => 'System', 'kickoff_meeting_id' => $meeting->id],
                    $this->momPlainText($meeting, null, null, $meetingDate, $readUrl),
                    $meeting->tenant_id,
                );
            }

            // §13's "Sangoe notification" — the in-app copy, which is how an
            // internal participant whose e-mail we do not hold hears about it.
            if ($a->user_id) {
                $this->invites->notifyInApp(
                    $meeting, (int) $a->user_id,
                    'Minutes issued: '.$meeting->title,
                    'The minutes of meeting '.($meeting->meeting_no ?: '#'.$meeting->id).' have been distributed.',
                );
            }
        }

        if ($phone) {
            $primaryToken = $this->momTokenFor($meeting, $vendor);
            $this->notifications->whatsapp(
                $phone,
                $this->momPlainText($meeting, $vendor, $ackUrl, $meetingDate,
                    $primaryToken ? FrontendUrl::to('/kickoff/mom/'.$primaryToken) : null),
                ['category' => 'System', 'kickoff_meeting_id' => $meeting->id],
            );
        }
    }

    /**
     * Who receives the minutes, and with which read token.
     *
     * One row per vendor on the meeting, primary first. Falls back to the single
     * resolved vendor for a meeting written before the subjects pivot existed —
     * that one uses the meeting-level token, which resolveMomByToken() also
     * accepts, so legacy meetings keep a working link.
     *
     * @return array<int, array{email: ?string, vendor: ?Vendor, token: ?string, is_primary: bool}>
     */
    private function momRecipients(KickoffMeeting $meeting, ?Vendor $primary, ?string $fallbackEmail): array
    {
        $meeting->loadMissing('subjects.subject');

        if ($meeting->subjects->isEmpty()) {
            return [[
                'email' => $fallbackEmail,
                'vendor' => $primary,
                'token' => $meeting->ack_token,
                'is_primary' => true,
            ]];
        }

        return $meeting->subjects->map(function (KickoffMeetingSubject $s) {
            $v = $s->subject instanceof Vendor ? $s->subject : null;

            return [
                'email' => $v?->email,
                'vendor' => $v,
                'token' => $s->ack_token,
                'is_primary' => (bool) $s->is_primary,
            ];
        })->all();
    }

    /** The read token belonging to one vendor, for the non-e-mail channels. */
    private function momTokenFor(KickoffMeeting $meeting, ?Vendor $vendor): ?string
    {
        if (! $vendor) {
            return $meeting->ack_token;
        }

        $row = $meeting->subjects()
            ->where('subject_type', $vendor::class)
            ->where('subject_id', $vendor->id)
            ->first();

        return $row?->ack_token ?: $meeting->ack_token;
    }

    /** The branded MOM e-mail. Kept beside its plain-text twin so they stay in step. */
    private function renderMomEmail(KickoffMeeting $meeting, ?Vendor $vendor, ?string $ackUrl, ?string $meetingDate, ?string $momUrl = null): string
    {
        $meeting->loadMissing(['attendees', 'momItems']);

        $details = array_filter([
            'Date' => $meetingDate,
            'Time' => optional($meeting->scheduled_at)->format('h:i A'),
            'Mode' => $meeting->mode ? ucfirst($meeting->mode) : null,
            // The PDF labels this the same way — an online meeting's "location"
            // is its joining link, and calling it Location would read as a place.
            ($meeting->mode === 'online' ? 'Meeting Link' : 'Location') => $meeting->location,
        ]);

        return view('emails.shared.kickoff_mom', [
            'meeting' => $meeting,
            'meetingDate' => $meetingDate,
            'recipientName' => $vendor?->company_name ?: ($meeting->attendees->first()->name ?? 'Sir/Madam'),
            'details' => $details,
            'attendees' => $meeting->attendees,
            'presentCount' => $meeting->attendees->where('attended', true)->count(),
            'momItems' => $meeting->momItems,
            'ackUrl' => $ackUrl,
            'momUrl' => $momUrl,
            'deadline' => optional($meeting->acknowledgement_deadline)->format('d M Y, h:i A'),
            'windowHours' => KickoffMeeting::ACK_WINDOW_HOURS,
            'companyName' => config('app.name', 'Our Company'),
            'logoUrl' => config('mail.logo_url'),
        ])->render();
    }

    /**
     * Plain-text twin — the multipart alternative and the WhatsApp body. Clients
     * that refuse HTML must still receive the acknowledgement link, or the whole
     * message becomes undeliverable in practice.
     */
    private function momPlainText(KickoffMeeting $meeting, ?Vendor $vendor, ?string $ackUrl, ?string $meetingDate, ?string $momUrl = null): string
    {
        $meeting->loadMissing('momItems');

        $lines = ['Dear '.($vendor?->company_name ?: 'Sir/Madam').',', ''];
        $lines[] = 'Please find below the minutes of the kickoff meeting'.($meetingDate ? ' held on '.$meetingDate : '').'.';
        $lines[] = '';

        if ($meeting->scheduled_at) {
            $lines[] = 'Date: '.$meeting->scheduled_at->format('d M Y').'   Time: '.$meeting->scheduled_at->format('h:i A');
        }
        if ($meeting->location) {
            $lines[] = ($meeting->mode === 'online' ? 'Meeting Link: ' : 'Location: ').$meeting->location;
        }

        if ($meeting->momItems->count()) {
            $lines[] = '';
            $lines[] = 'ACTION ITEMS';
            foreach ($meeting->momItems as $i => $item) {
                $lines[] = ($i + 1).'. '.($item->description ?: '—')
                    .($item->target_date ? ' (target '.$item->target_date->format('d M Y').')' : '')
                    .($item->remark ? ' — '.$item->remark : '');
            }
        }

        if ($meeting->minutes) {
            $lines[] = '';
            $lines[] = 'MINUTES';
            $lines[] = $meeting->minutes;
        }

        if ($momUrl) {
            $lines[] = '';
            $lines[] = 'View the signed minutes (no login needed): '.$momUrl;
        }

        if ($ackUrl) {
            $lines[] = '';
            $lines[] = 'ACKNOWLEDGMENT REQUIRED';
            if ($meeting->acknowledgement_deadline) {
                $lines[] = 'Deadline: '.$meeting->acknowledgement_deadline->format('d M Y, h:i A')
                    .' ('.KickoffMeeting::ACK_WINDOW_HOURS.' hours from now)';
            }
            $lines[] = 'Acknowledge here: '.$ackUrl;
        }

        return implode("\n", $lines);
    }

    /* ── Public acknowledgement (no auth — the token is the credential) ── */

    public function resolveByToken(string $token): KickoffMeeting
    {
        $meeting = KickoffMeeting::where('ack_token', $token)->with(['attendees', 'kickoffable'])->first();

        if (! $meeting) {
            throw new BusinessException('This acknowledgement link is not valid. It may already have been used.', 404);
        }

        return $meeting;
    }

    /**
     * Resolve a meeting for READING its minutes from a public link.
     *
     * Accepts either token in the acknowledgement infrastructure: the per-vendor
     * one on kickoff_meeting_subjects (what the e-mail now sends, so each vendor
     * holds its own revocable credential) or the meeting-level one, so links
     * already in inboxes keep working.
     *
     * Read-only and repeatable by design — unlike acknowledge(), this never burns
     * the token. It resolves mom_path at call time, so a MOM regenerated after
     * attendance changes is what opens; there is no second copy to go stale.
     */
    public function resolveMomByToken(string $token): KickoffMeeting
    {
        $subject = KickoffMeetingSubject::where('ack_token', $token)->first();

        $meeting = $subject
            ? KickoffMeeting::find($subject->kickoff_meeting_id)
            : KickoffMeeting::where('ack_token', $token)->first();

        // A per-recipient read token (Meeting.docx §13). Every non-vendor
        // recipient now gets one of these instead of a shared link, which is
        // what makes "who has actually viewed the minutes" answerable.
        if (! $meeting) {
            $dist = MeetingDistribution::where('token', $token)->first();
            if ($dist) {
                $meeting = KickoffMeeting::find($dist->kickoff_meeting_id);
                if ($meeting && (int) $dist->tenant_id !== (int) $meeting->tenant_id) {
                    throw new BusinessException('This link is not valid.', 404);
                }
            }
        }

        // One message for every miss — unknown, tampered, or deleted. Telling them
        // apart would let someone probe which tokens exist.
        if (! $meeting) {
            throw new BusinessException('This link is not valid.', 404);
        }

        // A subject row carries its own tenant; it must be the meeting's. Belt and
        // braces against a pivot row ever being written across tenants.
        if ($subject && (int) $subject->tenant_id !== (int) $meeting->tenant_id) {
            throw new BusinessException('This link is not valid.', 404);
        }

        // Same deadline as the acknowledgement window, but read DIRECTLY rather
        // than through $meeting->acknowledgement_expired: that accessor means
        // "the window shut without a signature", so it flips to false the moment
        // someone acknowledges — which would leave a bearer token in an inbox
        // working forever. A read link has to expire on the clock alone.
        //
        // A meeting never put on a clock (no deadline) stays readable, exactly as
        // acknowledge() treats it. Past the deadline the portal is the way in.
        if ($meeting->acknowledgement_deadline && $meeting->acknowledgement_deadline->isPast()) {
            throw new BusinessException('This link has expired. Please sign in to the vendor portal to view the minutes.', 410);
        }

        if (! $meeting->mom_path || ! Storage::disk(self::DISK)->exists($meeting->mom_path)) {
            throw new BusinessException('The minutes for this meeting are not available yet.', 404);
        }

        return $meeting;
    }

    public function acknowledge(KickoffMeeting $meeting, array $data, array $meta): KickoffMeeting
    {
        if ($meeting->acknowledged_at) {
            throw new BusinessException('These minutes have already been acknowledged.');
        }

        // The window only binds meetings published after it existed; one with no
        // deadline was never put on a clock, so it stays open.
        if ($meeting->acknowledgement_expired) {
            $meeting->update(['acknowledgement_status' => KickoffMeeting::ACK_EXPIRED]);

            throw new BusinessException(
                'This acknowledgement link expired on '
                .$meeting->acknowledgement_deadline->format('d M Y H:i')
                .'. Ask the coordinator to re-send the minutes.'
            );
        }

        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new BusinessException('Please enter your name to acknowledge the minutes.');
        }

        $comment = trim((string) ($data['comment'] ?? ''));

        // The vendor's response is one of three (Meeting.docx §13): a plain
        // acknowledgement, a dispute (they disagree with what was minuted), or a
        // request for correction (a factual fix before they sign). Anything else —
        // or nothing — is treated as a plain acknowledgement.
        $responseType = in_array($data['response_type'] ?? null, ['acknowledge', 'dispute', 'correction'], true)
            ? $data['response_type']
            : 'acknowledge';

        // A dispute or a correction request needs the reason spelled out — that is
        // the whole content of the response.
        if ($responseType !== 'acknowledge' && $comment === '') {
            throw new BusinessException($responseType === 'dispute'
                ? 'Please describe what you disagree with so it can be addressed.'
                : 'Please describe the correction needed before you can request it.');
        }

        $ackStatus = match ($responseType) {
            'dispute' => 'disputed',
            'correction' => 'correction_requested',
            default => KickoffMeeting::ACK_ACKNOWLEDGED,
        };

        $changes = [
            'acknowledged_ip' => $meta['ip'] ?? null,
            'acknowledgement_status' => $ackStatus,
            'acknowledgement_response_type' => $responseType,
            // Optional for a plain ack; mandatory (enforced above) otherwise.
            'acknowledgement_comment' => $comment !== '' ? $comment : null,
            // Burn the token — an acknowledgement link is single-use.
            'ack_token' => null,
        ];

        if ($responseType === 'acknowledge') {
            // Only a clean acknowledgement closes the loop and stamps the signer.
            $changes['acknowledged_at'] = now();
            $changes['acknowledged_by_name'] = $data['name'];
        } else {
            // A dispute / correction sends the minutes back to Draft so the
            // coordinator can revise and re-issue them; the signer is recorded but
            // the meeting is not yet acknowledged.
            $changes['acknowledged_by_name'] = $data['name'];
            $changes['mom_status'] = MomApprovalStatus::DRAFT;
        }

        $meeting->update($changes);

        $verb = match ($responseType) {
            'dispute' => 'disputed',
            'correction' => 'requested a correction to',
            default => 'acknowledged',
        };
        $meeting->recordAudit('acknowledged', null, "Minutes {$verb} by {$data['name']}"
            .($comment !== '' ? ": {$comment}" : ''), [
                'ip' => $meta['ip'] ?? null,
                'response_type' => $responseType,
            ]);
        Log::channel('tpv')->info('Kickoff minutes '.$verb, [
            'meeting_id' => $meeting->id, 'by' => $data['name'], 'ip' => $meta['ip'] ?? null,
        ]);

        return $meeting->fresh();
    }

    public function delete(KickoffMeeting $meeting, User $actor): void
    {
        $meeting->recordAudit('deleted', $actor, "Kickoff '{$meeting->title}' deleted");
        $meeting->delete();
    }

    /**
     * Mark who actually turned up — a post-meeting edit of the attendance flags,
     * distinct from rebuilding the roster. Only rows that belong to this meeting
     * are touched; unknown ids are ignored rather than erroring the whole call.
     */
    /**
     * Mark attendance.
     *
     * Accepts either the original boolean `attended` or the richer
     * `attendance_status` (Present/Late/Absent). Whichever arrives, BOTH columns
     * are written and kept consistent — `attended` is still what the PDF, the
     * portal and existing API consumers read, so it must never drift from the
     * status. Late counts as having turned up.
     */
    public function markAttendance(KickoffMeeting $meeting, array $rows, User $actor): KickoffMeeting
    {
        $present = 0;
        $late = 0;
        $absent = 0;

        foreach ($rows as $row) {
            $attendee = $meeting->attendees()->whereKey($row['id'])->first();
            if (! $attendee) {
                continue;
            }

            $changes = [];

            if (array_key_exists('attendance_status', $row)) {
                $status = $row['attendance_status'];

                if ($status === null || $status === '') {
                    // Explicitly un-marking: back to "not marked yet".
                    $changes['attendance_status'] = null;
                    $changes['attended'] = false;
                } else {
                    if (! in_array($status, KickoffAttendee::STATUSES, true)) {
                        throw new BusinessException('Unknown attendance status: '.$status);
                    }
                    $changes['attendance_status'] = $status;
                    $changes['attended'] = in_array($status, KickoffAttendee::ATTENDING, true);
                }
            } elseif (array_key_exists('attended', $row)) {
                // Legacy boolean caller — derive a status so the two agree.
                $attended = ! empty($row['attended']);
                $changes['attended'] = $attended;
                $changes['attendance_status'] = $attended ? KickoffAttendee::PRESENT : KickoffAttendee::ABSENT;
            }

            if (array_key_exists('remark', $row)) {
                $changes['remark'] = $row['remark'];
            }

            if (! $changes) {
                continue;
            }

            $attendee->update($changes);

            match ($attendee->fresh()->attendance_status) {
                KickoffAttendee::PRESENT => $present++,
                KickoffAttendee::LATE => $late++,
                KickoffAttendee::ABSENT => $absent++,
                default => null,
            };
        }

        $meeting->recordAudit('attendance_marked', $actor, "Attendance updated: {$present} present, {$late} late, {$absent} absent");
        Log::channel('tpv')->info('Kickoff attendance marked', [
            'meeting_id' => $meeting->id, 'actor_id' => $actor->id,
            'present' => $present, 'late' => $late, 'absent' => $absent,
        ]);

        // The MOM prints the attendance table, so a document generated before this
        // point now contradicts the record — it kept showing everyone Absent after
        // they had been marked Present, and the only clue was a "Regenerate PDF"
        // button nobody had reason to press.
        //
        // Only refreshed when a document already exists: generating one here would
        // create a MOM for a meeting whose minutes have not been written yet.
        if ($meeting->mom_path) {
            try {
                $this->generateMom($meeting->fresh(), $actor);
            } catch (\Throwable $e) {
                // Attendance is saved either way. A failed re-render must not undo
                // it — the stale document is a smaller problem than losing the marks.
                Log::channel('tpv')->warning('Could not refresh MOM after attendance change', [
                    'meeting_id' => $meeting->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Send a manual meeting reminder. Email is a real send; WhatsApp and SMS are
     * stubbed (log-and-queue) upstream, so the per-channel result reports exactly
     * what happened and the UI must not imply WhatsApp/SMS were delivered.
     */
    public function sendReminder(KickoffMeeting $meeting, User $actor): array
    {
        $meeting->loadMissing('attendees', 'kickoffable');

        $subjectName = KickoffSubject::nameOf($meeting->kickoffable);
        $when = $meeting->scheduled_at ? $meeting->scheduled_at->format('d M Y, g:i A') : 'a date to be confirmed';
        $where = $meeting->location ? " at {$meeting->location}" : '';
        $subject = "Reminder: {$meeting->title}";
        $body = "This is a reminder for the kickoff meeting \"{$meeting->title}\""
            .($subjectName ? " with {$subjectName}" : '')
            .", scheduled for {$when}{$where}.";

        // Email — one per attendee that has an address.
        $email = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($meeting->attendees as $attendee) {
            $result = $this->notifications->email($attendee->email, $subject, $body,
                ['category' => 'System', 'kickoff_meeting_id' => $meeting->id]);
            $email[$result] = ($email[$result] ?? 0) + 1;
        }

        // WhatsApp / SMS — stubs. Directed at the vendor's phone (the attendee
        // registry holds no numbers). 'queued' means logged, never delivered.
        $phone = $this->subjectPhone($meeting->kickoffable);
        $whatsapp = $this->notifications->whatsapp($phone, $body, ['category' => 'System', 'kickoff_meeting_id' => $meeting->id]);
        $sms = $this->notifications->sms($phone, $body, ['category' => 'System', 'kickoff_meeting_id' => $meeting->id]);

        $meeting->recordAudit('reminder_sent', $actor, "Reminder sent — email: {$email['sent']} sent, WhatsApp/SMS queued");
        Log::channel('tpv')->info('Kickoff reminder sent', [
            'meeting_id' => $meeting->id, 'actor_id' => $actor->id, 'email' => $email,
        ]);

        return [
            'recipients' => $meeting->attendees->count(),
            'email' => $email,
            'whatsapp' => $whatsapp,
            'sms' => $sms,
        ];
    }

    /**
     * Build the Minutes-of-Meeting PDF from existing meeting data only — no new
     * fields, just what's already recorded. Regenerating replaces the previous
     * file so there is only ever one current MoM document.
     */
    public function generateMom(KickoffMeeting $meeting, User $actor): KickoffMeeting
    {
        // The structured registers are loaded too — the MOM prints them, and a
        // lazy-load inside the Blade would be a query per row.
        $meeting->loadMissing(
            'attendees', 'kickoffable', 'creator', 'subjects.subject',
            'agendaItems.owner', 'momItems.responsible', 'decisions.decidedBy', 'issues.owner',
        );

        // Every vendor on the meeting, primary first. Falls back to the single
        // kickoffable so a record predating multi-vendor still names its vendor.
        $subjectNames = $meeting->subjects->isNotEmpty()
            ? $meeting->subjects->map(fn ($s) => KickoffSubject::nameOf($s->subject))->filter()->values()->all()
            : array_filter([KickoffSubject::nameOf($meeting->kickoffable)]);

        // The project label for the MOM header (Meeting.docx §7). Soft link, so a
        // project that no longer resolves simply prints as a dash.
        $projectName = $meeting->project_id
            ? app(ProjectDirectoryContract::class)->labelFor((int) $meeting->project_id, (int) $meeting->tenant_id)
            : null;

        $pdf = Pdf::loadView('pdf.kickoff_mom', [
            'meeting' => $meeting,
            'tenant' => Tenant::find($meeting->tenant_id),
            'projectName' => $projectName,
            'subjectNames' => $subjectNames,
            'subjectName' => KickoffSubject::nameOf($meeting->kickoffable),
            'generatedBy' => $actor->name,
            'generatedAt' => now(),
        ])->setPaper('a4');

        // Replace the prior document rather than orphan it on disk.
        if ($meeting->mom_path && Storage::disk(self::DISK)->exists($meeting->mom_path)) {
            Storage::disk(self::DISK)->delete($meeting->mom_path);
        }

        $path = "tenant-{$meeting->tenant_id}/meeting-{$meeting->id}/mom-".Str::random(12).'.pdf';
        Storage::disk(self::DISK)->put($path, $pdf->output());

        $meeting->update(['mom_path' => $path]);
        $this->syncOnboardingPointer($meeting->kickoffable, $meeting->id);
        $meeting->recordAudit('mom_generated', $actor, 'Minutes of meeting PDF generated');

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * The additional "Related To" links a meeting-born task should carry
     * (Meeting.docx §8) - project, customer and the meeting itself, minus
     * whichever one is already the primary link.
     *
     * A task has ONE primary link, so everything else in the doc's chain
     * (Meeting -> Project -> Vendor -> Work Package -> Person) has to ride as an
     * additional relation. TaskRepository's scope matches those too, so the same
     * action now shows on the project's board as well as the vendor's.
     *
     * @return array<int, array{rel_type:string, rel_id:int}>
     */
    private function taskRelationsFor(?KickoffMeeting $meeting, string $relType, ?int $relId): array
    {
        if (! $meeting) {
            return [];
        }

        $rel = [];
        if ($meeting->project_id) {
            $rel[] = ['rel_type' => 'project', 'rel_id' => (int) $meeting->project_id];
        }
        if ($meeting->client_id) {
            $rel[] = ['rel_type' => 'customer', 'rel_id' => (int) $meeting->client_id];
        }
        if ($meeting->kickoffable instanceof Vendor) {
            $rel[] = ['rel_type' => 'tpv_vendor', 'rel_id' => (int) $meeting->kickoffable->id];
        }
        $rel[] = ['rel_type' => 'meeting', 'rel_id' => (int) $meeting->id];

        // The primary link is already on the task; repeating it as a relation
        // would show the same chip twice.
        return array_values(array_filter(
            $rel,
            fn ($r) => ! ($r['rel_type'] === $relType && $relId !== null && $r['rel_id'] === $relId)
        ));
    }


    /* ── internals ─────────────────────────────────────────────── */

    /** The vendor phone behind a subject, for the stubbed WhatsApp/SMS channels. */
    private function subjectPhone(?object $subject): ?string
    {
        if ($subject instanceof Vendor) {
            return $subject->phone;
        }
        if ($subject instanceof TpvOnboarding) {
            return $subject->vendor?->phone;
        }

        return null;
    }

    /**
     * Rebuild the attendee list from the payload. A vendor_contact_id is verified
     * against the master in the same tenant, and its canonical name/email are
     * copied so the registry stays truthful even if typed differently.
     */
    /**
     * Build the single displayable `location` string from the structured parts.
     *
     * Every existing consumer — the MOM PDF, the reminder email, the portal —
     * reads `location`. Rather than teach each of them about three new columns,
     * the parts are composed into it on write, so old readers keep working and
     * new ones can use the structured fields.
     *
     * An explicit `location` in the payload still wins: a caller that only knows
     * about the old field (or a meeting held somewhere that isn't a city/venue)
     * must not have its value overwritten by an empty composition.
     */
    private function composeLocation(array $data, ?KickoffMeeting $existing = null): ?string
    {
        if (array_key_exists('location', $data) && trim((string) $data['location']) !== '') {
            return $data['location'];
        }

        // `location_detail` is what the existing Kickoff form calls the venue.
        if (! array_key_exists('venue', $data) && array_key_exists('location_detail', $data)) {
            $data['venue'] = $data['location_detail'];
        }

        $parts = [];
        foreach (['venue', 'address', 'city'] as $part) {
            $value = array_key_exists($part, $data) ? $data[$part] : $existing?->{$part};
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        // Nothing structured supplied — leave whatever is already there alone.
        return $parts ? implode(', ', $parts) : ($data['location'] ?? null);
    }

    /**
     * Replace the meeting's itemised minutes.
     *
     * Wholesale replace rather than diff: the form posts the full list every
     * time, an item has no identity outside its meeting, and reconciling by
     * index would silently re-point a responsible person when a row is deleted.
     *
     * responsible_attendee_id is validated against THIS meeting's attendees, so
     * an item cannot be assigned to somebody who was never in the room — or to
     * an attendee belonging to another tenant's meeting.
     */
    /**
     * Sync the itemised minutes from the form. UPSERTS by id rather than deleting
     * and recreating, so an action's lifecycle fields (status, evidence,
     * verification) — owned by the Action Engine, not the form — survive a meeting
     * edit. Only the content fields the form owns are (re)written here; items the
     * form no longer contains are removed.
     */
    private function replaceMomItems(KickoffMeeting $meeting, array $items, int $tenantId, ?array $agendaMap = null): void
    {
        $validAttendeeIds = $meeting->attendees()->pluck('id')->all();
        $agendaValidIds = $meeting->agendaItems()->pluck('id')->all();
        $priorities = config('meetings.priorities', ['Low', 'Medium', 'High']);
        $keepIds = [];
        $order = 0;
        // client_key (string) → saved action id, so a second pass can wire the
        // action-to-action dependencies once every row exists (Meeting.docx §8).
        $actionKeyToId = [];
        // saved action id → its requested depends-on client_key, resolved below.
        $dependsIntent = [];

        foreach ($items as $item) {
            $description = trim((string) ($item['description'] ?? ''));

            // An item with no description is an empty row the user left behind.
            if ($description === '') {
                continue;
            }

            $responsible = $item['responsible_attendee_id'] ?? null;
            if ($responsible !== null && ! in_array((int) $responsible, $validAttendeeIds, true)) {
                $responsible = null;
            }

            // The existing form posts `responsible` as a comma-separated list of
            // typed names. Where a name matches an attendee we resolve it to the
            // link; anything left over is kept verbatim rather than discarded.
            $names = trim((string) ($item['responsible'] ?? $item['responsible_names'] ?? ''));

            if ($responsible === null && $names !== '') {
                $first = trim(explode(',', $names)[0]);
                $match = $meeting->attendees->first(
                    fn ($a) => strcasecmp(trim((string) $a->name), $first) === 0
                );
                $responsible = $match?->id;
            }

            $priority = $item['priority'] ?? null;
            if ($priority !== null && ! in_array($priority, $priorities, true)) {
                $priority = null;
            }

            // The agenda item this action came out of (Meeting.docx §7). Resolved
            // through the same-submission agenda map when present, else validated as
            // an existing agenda id on this meeting; anything unresolved is dropped.
            $agendaId = $this->resolveAgendaLink($item, $agendaMap, $agendaValidIds);

            // Content fields only — never status/evidence/verification.
            $content = [
                'description' => $description,
                'responsible_attendee_id' => $responsible,
                'responsible_names' => $names !== '' ? $names : null,
                'responsible_org' => $item['responsible_org'] ?? null,
                // `remarks` is what the existing form sends.
                'remark' => $item['remark'] ?? $item['remarks'] ?? null,
                'notes' => $item['notes'] ?? null,
                'target_date' => $item['target_date'] ?? null,
                'priority' => $priority,
                'sort_order' => $order++,
            ];
            // Only re-link the agenda when the agenda was part of this submission;
            // otherwise leave whatever the row already points at untouched.
            if ($agendaMap !== null || array_key_exists('agenda_client_key', $item) || array_key_exists('agenda_item_id', $item)) {
                $content['agenda_item_id'] = $agendaId;
            }

            // An id that belongs to THIS meeting → update in place, preserving its
            // Action-Engine state. Anything else is a new action.
            $existing = ! empty($item['id'])
                ? $meeting->momItems()->whereKey($item['id'])->first()
                : null;

            if ($existing) {
                $existing->update($content);
                $keepIds[] = $existing->id;
                $savedId = $existing->id;
            } else {
                $created = KickoffMomItem::create([
                    ...$content,
                    'tenant_id' => $tenantId,
                    'kickoff_meeting_id' => $meeting->id,
                    'action_ref' => $this->nextActionRef($tenantId),
                    'status' => MomActionStatus::OPEN,
                    // Provenance for a carried-forward action. Set only on create —
                    // it is immutable once written, and marks the origin item as
                    // already rolled forward so carry-forward never offers it again.
                    'carried_from_id' => $this->carriedFromId($item, KickoffMomItem::class, $tenantId),
                ]);
                $keepIds[] = $created->id;
                $savedId = $created->id;
            }

            // Index this row by every key it can be referenced by (its client_key
            // and its prior server id), so a dependency pointing at it resolves.
            foreach ([$item['client_key'] ?? null, $item['id'] ?? null] as $k) {
                if ($k !== null && $k !== '') {
                    $actionKeyToId[(string) $k] = $savedId;
                }
            }
            if (array_key_exists('depends_on_client_key', $item)) {
                $dependsIntent[$savedId] = $item['depends_on_client_key'];
            }
        }

        // Rows the form dropped are removed (their tracking goes with them).
        $meeting->momItems()->whereNotIn('id', $keepIds ?: [0])->delete();

        // Second pass: wire dependencies now that every action id is known. A key
        // that does not resolve, or a self-reference, clears the dependency.
        foreach ($dependsIntent as $actionId => $key) {
            $depId = ($key !== null && $key !== '' && isset($actionKeyToId[(string) $key]))
                ? $actionKeyToId[(string) $key]
                : null;
            if ($depId === $actionId) {
                $depId = null;
            }
            KickoffMomItem::whereKey($actionId)->update(['depends_on_id' => $depId]);
        }
    }

    /**
     * Resolve a row's agenda link to an agenda id on this meeting, or null.
     * Prefers the same-submission client-key map; falls back to an existing id.
     */
    private function resolveAgendaLink(array $row, ?array $agendaMap, array $validIds): ?int
    {
        $key = $row['agenda_client_key'] ?? $row['agenda_item_id'] ?? null;
        if ($key === null || $key === '') {
            return null;
        }
        if ($agendaMap !== null && isset($agendaMap[(string) $key])) {
            return $agendaMap[(string) $key];
        }

        return in_array((int) $key, $validIds, true) ? (int) $key : null;
    }

    /** Next per-tenant, per-year action reference (ACT-YYYY-NNNN). */
    private function nextActionRef(int $tenantId): string
    {
        $year = date('Y');
        $n = KickoffMomItem::where('tenant_id', $tenantId)
            ->where('action_ref', 'like', "ACT-{$year}-%")
            ->count() + 1;

        return sprintf('ACT-%s-%04d', $year, $n);
    }

    /**
     * Progress a single MOM action through its lifecycle (the Action Engine).
     * Guards the transition, records evidence/verification, and enforces that a
     * closure carries evidence or a verification note (business rule 12).
     */
    public function progressAction(KickoffMomItem $item, array $data, User $actor): KickoffMomItem
    {
        $to = $data['status'] ?? null;
        $fromStatus = $item->status;   // captured before the update, for the §8 task sync
        if ($to !== null && $to !== $item->status && ! MomActionStatus::canTransition($item->status, $to)) {
            throw new BusinessException(
                'Cannot move a '.MomActionStatus::label($item->status).' action to '.MomActionStatus::label($to).'.'
            );
        }

        $changes = [];
        foreach (['responsible_org', 'target_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        // The verifier's note lives in verification_note — a field the meeting form
        // does not own — so a later form edit can never overwrite it.
        if (array_key_exists('note', $data)) {
            $changes['verification_note'] = $data['note'];
        }
        if (! empty($data['priority']) && in_array($data['priority'], config('meetings.priorities', ['Low', 'Medium', 'High']), true)) {
            $changes['priority'] = $data['priority'];
        }

        // Evidence file — decoded from base64 or handed in already-stored by the
        // controller as evidence_path.
        if (! empty($data['evidence_path'])) {
            $changes['evidence_path'] = $data['evidence_path'];
        } elseif (! empty($data['evidence_data']) && str_contains($data['evidence_data'], 'base64,')) {
            $binary = base64_decode(explode('base64,', $data['evidence_data'])[1]);
            $path = 'kickoff/action-evidence/ev_'.uniqid().'.png';
            Storage::disk(self::DISK)->put($path, $binary);
            $changes['evidence_path'] = $path;
        }

        if ($to !== null && $to !== $item->status) {
            if ($to === MomActionStatus::CLOSED) {
                $hasEvidence = ! empty($changes['evidence_path'] ?? $item->evidence_path);
                $hasNote = trim((string) ($changes['verification_note'] ?? $item->verification_note)) !== '';
                if (! $hasEvidence && ! $hasNote) {
                    throw new BusinessException('Closing an action needs evidence or a verification note.');
                }
                $changes['closed_at'] = now();
                $changes['verified_at'] = now();
                $changes['verified_by'] = $actor->id;
            }
            if ($to === MomActionStatus::REOPENED) {
                $changes['closed_at'] = null;
                $changes['verified_at'] = null;
                $changes['verified_by'] = null;
            }
            $changes['status'] = $to;
        }

        $item->update($changes);

        // §8 two-way sync: closing/reopening the action reflects onto its linked
        // Task, and vice-versa the Task board can move it back. Best-effort — a
        // failure in the Task module must not fail the action update.
        if ($item->task_id && $to !== null && $to !== $fromStatus) {
            try {
                if ($to === MomActionStatus::CLOSED) {
                    Task::whereKey($item->task_id)
                        ->update(['status' => 'finished', 'date_finished' => now()]);
                } elseif ($to === MomActionStatus::REOPENED) {
                    Task::whereKey($item->task_id)
                        ->update(['status' => 'in_progress', 'date_finished' => null]);
                }
            } catch (\Throwable $e) {
                Log::channel('tpv')->warning('Action→Task status sync failed', [
                    'action_id' => $item->id, 'task_id' => $item->task_id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $item->meeting?->recordAudit('action_updated', $actor,
            "Action {$item->action_ref} → ".MomActionStatus::label($item->status));

        return $item->fresh(['responsible:id,name', 'verifier:id,name', 'agendaItem:id,item', 'task:id,name,status,priority,due_date,date_finished']);
    }

    /**
     * Turn a MOM action into a real Sangoe Task (Meeting.docx §8). The task is
     * linked back to the vendor (rel_type=tpv_vendor) so it appears on that
     * vendor's Tasks tab, carries the action's owner/priority/due-date, and its id
     * is stamped on the action so the two stay connected. Idempotent: an action
     * already pushed is refused rather than duplicated.
     */
    public function pushActionToTask(KickoffMomItem $item, User $actor): KickoffMomItem
    {
        if ($item->task_id) {
            throw new BusinessException('This action is already linked to a task.');
        }

        $meeting = $item->meeting()->with(['kickoffable', 'attendees'])->first();
        $title = trim(strip_tags((string) $item->description));
        if ($title === '') {
            throw new BusinessException('Add a description before creating a task from this action.');
        }
        $title = mb_substr($title, 0, 200);

        // Link the task to the vendor when the meeting is about one, so it shows on
        // the vendor's Tasks tab (VendorTaskLink reads rel_type/rel_id).
        $relType = 'standalone';
        $relId = null;
        if ($meeting?->kickoffable instanceof Vendor) {
            $relType = 'tpv_vendor';
            $relId = $meeting->kickoffable->id;
        }

        // The responsible attendee's login becomes the assignee, when they have one.
        $assigneeIds = [];
        if ($item->responsible_attendee_id) {
            $uid = $meeting?->attendees->firstWhere('id', $item->responsible_attendee_id)?->user_id;
            if ($uid) {
                $assigneeIds[] = $uid;
            }
        }

        $priorityMap = ['Low' => 'low', 'Medium' => 'medium', 'High' => 'high', 'Urgent' => 'urgent'];
        $priority = $priorityMap[$item->priority] ?? 'medium';

        $backlink = 'From meeting '.($meeting?->meeting_no ?: ('#'.$meeting?->id))
            .' · action '.$item->action_ref;

        $data = [
            'name' => $title,
            'description' => (string) $item->description."\n\n<p><em>{$backlink}</em></p>",
            'priority' => $priority,
            'start_date' => now()->toDateString(),
            'due_date' => optional($item->target_date)->toDateString(),
            'rel_type' => $relType,
            'rel_id' => $relId,
            'assignee_ids' => $assigneeIds,
            'relations' => $this->taskRelationsFor($meeting, $relType, $relId),
        ];

        $task = app(TaskService::class)->create($data, $actor->tenant_id, $actor->id);

        $item->forceFill(['task_id' => $task->id])->save();
        $meeting?->recordAudit('action_pushed_to_task', $actor,
            "Action {$item->action_ref} pushed to task #{$task->id}");

        return $item->fresh(['responsible:id,name', 'verifier:id,name', 'agendaItem:id,item', 'task:id,name,status,priority,due_date,date_finished']);
    }

    /* ── Decision register (Meeting.docx §9) ────────────────────────────────── */

    /** Upsert the meeting's decisions by id. Content-owned; no separate engine. */
    private function syncDecisions(KickoffMeeting $meeting, array $rows, int $tenantId, ?array $agendaMap = null): void
    {
        $validAttendeeIds = $meeting->attendees()->pluck('id')->all();
        $agendaValidIds = $meeting->agendaItems()->pluck('id')->all();
        $statuses = config('meetings.decision_statuses', ['Active', 'Superseded', 'Rescinded']);
        $keepIds = [];
        $order = 0;

        foreach ($rows as $row) {
            $text = trim((string) ($row['decision'] ?? ''));
            if ($text === '') {
                continue;
            }

            $by = $row['decided_by_attendee_id'] ?? null;
            if ($by !== null && ! in_array((int) $by, $validAttendeeIds, true)) {
                $by = null;
            }
            $names = trim((string) ($row['decided_by_names'] ?? $row['decided_by'] ?? ''));
            $status = in_array($row['status'] ?? null, $statuses, true) ? $row['status'] : 'Active';

            $content = [
                'decision' => $text,
                'decided_by_attendee_id' => $by,
                'decided_by_names' => $names !== '' ? $names : null,
                'impact' => $row['impact'] ?? null,
                'effective_date' => $row['effective_date'] ?? null,
                'status' => $status,
                'sort_order' => $order++,
            ];
            // The agenda item this decision was taken under (Meeting.docx §7).
            if ($agendaMap !== null || array_key_exists('agenda_client_key', $row) || array_key_exists('agenda_item_id', $row)) {
                $content['agenda_item_id'] = $this->resolveAgendaLink($row, $agendaMap, $agendaValidIds);
            }

            $existing = ! empty($row['id']) ? $meeting->decisions()->whereKey($row['id'])->first() : null;
            if ($existing) {
                $existing->update($content);
                $keepIds[] = $existing->id;
            } else {
                $created = MeetingDecision::create([
                    ...$content,
                    'tenant_id' => $tenantId,
                    'kickoff_meeting_id' => $meeting->id,
                    'decision_ref' => $this->nextRef($tenantId, MeetingDecision::class, 'decision_ref', 'DEC'),
                ]);
                $keepIds[] = $created->id;
            }
        }

        $meeting->decisions()->whereNotIn('id', $keepIds ?: [0])->delete();
    }

    /* ── Issues raised (Meeting.docx §10) ───────────────────────────────────── */

    /**
     * Upsert the meeting's issues by id. Writes CONTENT only — status and the
     * conversion marker are owned by the issue engine and survive a form edit.
     */
    private function syncIssues(KickoffMeeting $meeting, array $rows, int $tenantId): void
    {
        $validAttendeeIds = $meeting->attendees()->pluck('id')->all();
        $severities = config('meetings.issue_severities', ['Low', 'Medium', 'High', 'Critical']);
        $keepIds = [];
        $order = 0;

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $owner = $row['owner_attendee_id'] ?? null;
            if ($owner !== null && ! in_array((int) $owner, $validAttendeeIds, true)) {
                $owner = null;
            }
            $names = trim((string) ($row['owner_names'] ?? $row['owner'] ?? ''));
            $severity = in_array($row['severity'] ?? null, $severities, true) ? $row['severity'] : null;

            $content = [
                'title' => $title,
                'description' => $row['description'] ?? null,
                'category' => $row['category'] ?? null,
                'severity' => $severity,
                'owner_attendee_id' => $owner,
                'owner_names' => $names !== '' ? $names : null,
                'due_date' => $row['due_date'] ?? null,
                'sort_order' => $order++,
            ];

            $existing = ! empty($row['id']) ? $meeting->issues()->whereKey($row['id'])->first() : null;
            if ($existing) {
                $existing->update($content);
                $keepIds[] = $existing->id;
            } else {
                $created = MeetingIssue::create([
                    ...$content,
                    'tenant_id' => $tenantId,
                    'kickoff_meeting_id' => $meeting->id,
                    'issue_ref' => $this->nextRef($tenantId, MeetingIssue::class, 'issue_ref', 'ISS'),
                    'status' => MeetingIssueStatus::OPEN,
                    'carried_from_id' => $this->carriedFromId($row, MeetingIssue::class, $tenantId),
                ]);
                $keepIds[] = $created->id;
            }
        }

        $meeting->issues()->whereNotIn('id', $keepIds ?: [0])->delete();
    }

    /** Progress an issue through its lifecycle. */
    public function progressIssue(MeetingIssue $issue, array $data, User $actor): MeetingIssue
    {
        $to = $data['status'] ?? null;
        if ($to !== null && $to !== $issue->status && ! MeetingIssueStatus::canTransition($issue->status, $to)) {
            throw new BusinessException(
                'Cannot move a '.MeetingIssueStatus::label($issue->status).' issue to '.MeetingIssueStatus::label($to).'.'
            );
        }

        $changes = [];
        foreach (['due_date', 'category', 'owner_names'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (! empty($data['severity']) && in_array($data['severity'], config('meetings.issue_severities', []), true)) {
            $changes['severity'] = $data['severity'];
        }
        if ($to !== null && $to !== $issue->status) {
            $changes['status'] = $to;
        }

        $issue->update($changes);
        $issue->meeting?->recordAudit('issue_updated', $actor,
            "Issue {$issue->issue_ref} → ".MeetingIssueStatus::label($issue->status));

        return $issue->fresh(['owner:id,name']);
    }

    /**
     * Escalate an issue into an HSSE Incident (Meeting.docx §10 — "convert the
     * issue into Task/NCR/CAPA/Incident/Approval"). Incident is the target wired
     * today; it creates a real HsseIncident against the meeting's vendor and links
     * it back on the issue. Severity is admin-chosen so a routine escalation does
     * not silently trip auto-suspension.
     */
    public function convertIssueToIncident(MeetingIssue $issue, array $data, User $actor): MeetingIssue
    {
        if ($issue->is_converted) {
            throw new BusinessException("This issue was already converted to {$issue->converted_to} ({$issue->converted_ref}).");
        }

        $vendorId = $this->meetingVendorId($issue->meeting);
        if (! $vendorId) {
            throw new BusinessException('This meeting is not linked to a vendor, so its issues cannot be raised as vendor incidents.');
        }

        $incident = app(IncidentService::class)->create($actor->tenant_id, [
            'vendor_id' => $vendorId,
            'title' => $issue->title,
            'type' => $data['type'] ?? 'Other',
            'severity' => $data['severity'] ?? 'Moderate',
            'description' => $issue->description,
            'stop_work' => (bool) ($data['stop_work'] ?? false),
        ], $actor);

        $issue->update([
            'converted_to' => 'Incident',
            'converted_ref' => $incident->reference,
            'converted_id' => $incident->id,
            // An escalated issue moves into progress — it is now tracked as an incident.
            'status' => MeetingIssueStatus::isOpen($issue->status) ? MeetingIssueStatus::IN_PROGRESS : $issue->status,
        ]);

        $issue->meeting?->recordAudit('issue_converted', $actor,
            "Issue {$issue->issue_ref} raised as incident {$incident->reference}");

        return $issue->fresh(['owner:id,name']);
    }

    /**
     * Escalate a meeting issue into a real NCR (Meeting.docx §10).
     *
     * The issue's own words carry over — a re-typed NCR drifts from the issue it
     * came from — and the conversion is stamped so it cannot be raised twice.
     */
    public function convertIssueToNcr(MeetingIssue $issue, array $data, User $actor): MeetingIssue
    {
        $this->assertConvertible($issue);

        $vendorId = $this->meetingVendorId($issue->meeting);
        if (! $vendorId) {
            throw new BusinessException('This meeting is not linked to a vendor, so its issues cannot be raised as NCRs.');
        }

        // Issue severity and NCR severity are the same four-step scale; only the
        // Critical/Major wording differs.
        $severity = ['Low' => 'Minor', 'Medium' => 'Major', 'High' => 'Major', 'Critical' => 'Critical'][$issue->severity] ?? 'Major';

        $ncr = app(TpvNcrService::class)->create([
            'vendor_id' => $vendorId,
            'project_id' => $issue->meeting?->project_id,
            'title' => $issue->title,
            'finding' => $issue->description ?: $issue->title,
            'requirement' => $data['requirement'] ?? null,
            'severity' => $data['severity'] ?? $severity,
            'due_date' => optional($issue->due_date)->toDateString(),
        ], $actor->tenant_id, $actor->id);

        return $this->stampConversion($issue, 'NCR', $ncr->reference, $ncr->id, $actor);
    }

    /** Escalate a meeting issue into a CAPA (Meeting.docx §10). */
    public function convertIssueToCapa(MeetingIssue $issue, array $data, User $actor): MeetingIssue
    {
        $this->assertConvertible($issue);

        $vendorId = $this->meetingVendorId($issue->meeting);
        if (! $vendorId) {
            throw new BusinessException('This meeting is not linked to a vendor, so its issues cannot be raised as CAPAs.');
        }

        $priority = ['Low' => 'Low', 'Medium' => 'Medium', 'High' => 'High', 'Critical' => 'Critical'][$issue->severity] ?? 'Medium';

        $capa = app(TpvCapaService::class)->create([
            'vendor_id' => $vendorId,
            // CapaSource::CLASSES maps 'meeting' onto KickoffMeeting, so the CAPA
            // links back to the meeting the issue was raised in.
            'source_kind' => 'meeting',
            'source_id' => $issue->kickoff_meeting_id,
            'title' => $issue->title,
            'type' => $data['type'] ?? 'Corrective',
            'root_cause' => $data['root_cause'] ?? null,
            'action' => $issue->description ?: $issue->title,
            'priority' => $data['priority'] ?? $priority,
            'due_date' => optional($issue->due_date)->toDateString(),
        ], $actor->tenant_id, $actor->id);

        return $this->stampConversion($issue, 'CAPA', $capa->reference, $capa->id, $actor);
    }

    /** Raise a meeting issue as an approval request (Meeting.docx §10). */
    public function convertIssueToApproval(MeetingIssue $issue, array $data, User $actor): MeetingIssue
    {
        $this->assertConvertible($issue);

        $priority = ['Low' => 'Low', 'Medium' => 'Medium', 'High' => 'High', 'Critical' => 'Urgent'][$issue->severity] ?? 'Medium';

        $approval = app(TpvApprovalService::class)->raise([
            'approval_type' => $data['approval_type'] ?? 'Other',
            'subject_type' => MeetingIssue::class,
            'subject_id' => $issue->id,
            'vendor_id' => $this->meetingVendorId($issue->meeting),
            'title' => $issue->title,
            'description' => $issue->description
                ?: ('Raised from meeting '.($issue->meeting?->meeting_no ?: '#'.$issue->kickoff_meeting_id)),
            'priority' => $data['priority'] ?? $priority,
        ], $actor->tenant_id, $actor->id);

        return $this->stampConversion($issue, 'Approval', $approval->reference, $approval->id, $actor);
    }

    /** One conversion per issue — a second would duplicate the escalation. */
    private function assertConvertible(MeetingIssue $issue): void
    {
        if ($issue->is_converted) {
            throw new BusinessException("This issue was already converted to {$issue->converted_to} ({$issue->converted_ref}).");
        }
    }

    /**
     * Record what an issue became. An escalated issue moves into progress: it is
     * now tracked in the record it became, not sitting untouched in the register.
     */
    private function stampConversion(MeetingIssue $issue, string $kind, ?string $ref, ?int $id, User $actor): MeetingIssue
    {
        $issue->update([
            'converted_to' => $kind,
            'converted_ref' => $ref,
            'converted_id' => $id,
            'status' => MeetingIssueStatus::isOpen($issue->status)
                ? MeetingIssueStatus::IN_PROGRESS
                : $issue->status,
        ]);

        $issue->meeting?->recordAudit('issue_converted', $actor,
            "Issue {$issue->issue_ref} raised as {$kind} {$ref}");

        return $issue->fresh(['owner:id,name']);
    }

    /**
     * Convert an issue into a real Sangoe Task (Meeting.docx §10 — "convert the
     * issue into Task/…"). Mirrors the action→task push: the task is linked to the
     * vendor and carries the issue's owner/severity/due-date; the issue records the
     * conversion so it is not converted twice.
     */
    public function convertIssueToTask(MeetingIssue $issue, User $actor): MeetingIssue
    {
        if ($issue->is_converted) {
            throw new BusinessException("This issue was already converted to {$issue->converted_to} ({$issue->converted_ref}).");
        }

        $meeting = $issue->meeting()->with(['kickoffable', 'attendees'])->first();
        $title = mb_substr(trim(strip_tags((string) $issue->title)), 0, 200);
        if ($title === '') {
            throw new BusinessException('Add a title before converting this issue to a task.');
        }

        $relType = 'standalone';
        $relId = null;
        if ($meeting?->kickoffable instanceof Vendor) {
            $relType = 'tpv_vendor';
            $relId = $meeting->kickoffable->id;
        }

        $assigneeIds = [];
        if ($issue->owner_attendee_id) {
            $uid = $meeting?->attendees->firstWhere('id', $issue->owner_attendee_id)?->user_id;
            if ($uid) {
                $assigneeIds[] = $uid;
            }
        }

        // Issue severity maps onto the task priority scale.
        $priorityMap = ['Low' => 'low', 'Medium' => 'medium', 'High' => 'high', 'Critical' => 'urgent'];
        $priority = $priorityMap[$issue->severity] ?? 'medium';

        $backlink = 'From meeting '.($meeting?->meeting_no ?: ('#'.$meeting?->id)).' · issue '.$issue->issue_ref;

        $task = app(TaskService::class)->create([
            'name' => $title,
            'description' => (string) $issue->description."\n\n<p><em>{$backlink}</em></p>",
            'priority' => $priority,
            'start_date' => now()->toDateString(),
            'due_date' => optional($issue->due_date)->toDateString(),
            'rel_type' => $relType,
            'rel_id' => $relId,
            'assignee_ids' => $assigneeIds,
            'relations' => $this->taskRelationsFor($meeting, $relType, $relId),
        ], $actor->tenant_id, $actor->id);

        $issue->update([
            'converted_to' => 'Task',
            'converted_ref' => 'TASK-'.$task->id,
            'converted_id' => $task->id,
            'status' => MeetingIssueStatus::isOpen($issue->status) ? MeetingIssueStatus::IN_PROGRESS : $issue->status,
        ]);

        $issue->meeting?->recordAudit('issue_converted', $actor,
            "Issue {$issue->issue_ref} converted to task #{$task->id}");

        return $issue->fresh(['owner:id,name']);
    }

    /** The vendor id behind a meeting's subject, if any (vendor or onboarding). */
    private function meetingVendorId(KickoffMeeting $meeting): ?int
    {
        if ($meeting->kickoffable_type === Vendor::class) {
            return (int) $meeting->kickoffable_id;
        }
        if ($meeting->kickoffable_type === TpvOnboarding::class) {
            return $meeting->kickoffable?->vendor_id;
        }

        return null;
    }

    /** Next per-tenant, per-year reference for a child record ({PREFIX}-YYYY-NNNN). */
    private function nextRef(int $tenantId, string $modelClass, string $column, string $prefix): string
    {
        $year = date('Y');
        $n = $modelClass::where('tenant_id', $tenantId)
            ->where($column, 'like', "{$prefix}-{$year}-%")
            ->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, $year, $n);
    }

    /* ── Carry-forward (Meeting.docx — open items roll into the next meeting) ── */

    /**
     * The still-open actions and issues from a subject's earlier meetings, so a
     * new meeting can start pre-loaded with what was left unfinished.
     *
     * "Earlier meetings" is the same set the registry lists for the subject — the
     * ones where it is the PRIMARY (kickoffable_*). An item already carried forward
     * once (something later points back at it via carried_from_id) is left out, so
     * it cannot be rolled forward twice and appear on two future meetings at once.
     *
     * @return array{actions: array<int, array<string, mixed>>, issues: array<int, array<string, mixed>>}
     */
    public function carryForwardItems(int $tenantId, ?string $subjectType, $subjectId, ?int $excludeMeetingId = null): array
    {
        $empty = ['actions' => [], 'issues' => [], 'previous_agenda' => null, 'previous_stats' => null];

        if (! $subjectType || ! $subjectId || ! KickoffSubject::isValid($subjectType)) {
            return $empty;
        }
        $class = KickoffSubject::classFor($subjectType);

        $priorMeetings = KickoffMeeting::forTenant($tenantId)
            ->where('kickoffable_type', $class)
            ->where('kickoffable_id', (int) $subjectId)
            ->when($excludeMeetingId, fn ($q) => $q->where('id', '!=', $excludeMeetingId))
            ->orderByDesc('scheduled_at')
            ->get(['id', 'title', 'reference', 'scheduled_at']);

        if ($priorMeetings->isEmpty()) {
            return $empty;
        }

        $meetingIndex = $priorMeetings->keyBy('id');
        $meetingIds = $priorMeetings->pluck('id')->all();

        // Origins already rolled forward — excluded so nothing is offered twice.
        $carriedActionOrigins = KickoffMomItem::where('tenant_id', $tenantId)
            ->whereNotNull('carried_from_id')->pluck('carried_from_id')->all();
        $carriedIssueOrigins = MeetingIssue::where('tenant_id', $tenantId)
            ->whereNotNull('carried_from_id')->pluck('carried_from_id')->all();

        $actions = KickoffMomItem::where('tenant_id', $tenantId)
            ->whereIn('kickoff_meeting_id', $meetingIds)
            ->whereIn('status', MomActionStatus::OPEN_STATES)
            ->when($carriedActionOrigins, fn ($q) => $q->whereNotIn('id', $carriedActionOrigins))
            ->orderByRaw('target_date is null, target_date')
            ->get()
            ->map(fn (KickoffMomItem $a) => [
                'id' => $a->id,
                'action_ref' => $a->action_ref,
                'description' => $a->description,
                'responsible_names' => $a->responsible_names,
                'responsible_org' => $a->responsible_org,
                'target_date' => optional($a->target_date)->toDateString(),
                'priority' => $a->priority,
                'status' => $a->status,
                'status_label' => $a->status_label,
                'is_overdue' => $a->is_overdue,
                'origin' => $this->originStamp($meetingIndex->get($a->kickoff_meeting_id)),
            ])->values()->all();

        $issues = MeetingIssue::where('tenant_id', $tenantId)
            ->whereIn('kickoff_meeting_id', $meetingIds)
            ->whereIn('status', MeetingIssueStatus::OPEN_STATES)
            ->when($carriedIssueOrigins, fn ($q) => $q->whereNotIn('id', $carriedIssueOrigins))
            ->orderByRaw('due_date is null, due_date')
            ->get()
            ->map(fn (MeetingIssue $i) => [
                'id' => $i->id,
                'issue_ref' => $i->issue_ref,
                'title' => $i->title,
                'description' => $i->description,
                'category' => $i->category,
                'severity' => $i->severity,
                'owner_names' => $i->owner_names,
                'due_date' => optional($i->due_date)->toDateString(),
                'status' => $i->status,
                'status_label' => $i->status_label,
                'is_overdue' => $i->is_overdue,
                'origin' => $this->originStamp($meetingIndex->get($i->kickoff_meeting_id)),
            ])->values()->all();

        // §3 copy-agenda-from-previous: the agenda of the most recent prior meeting
        // that actually has structured agenda items (skip meetings that never used
        // the Agenda Builder), offered for one-click reuse in the new meeting.
        $previousAgenda = null;
        $agendaByMeeting = MeetingAgendaItem::where('tenant_id', $tenantId)
            ->whereIn('kickoff_meeting_id', $meetingIds)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('kickoff_meeting_id');
        foreach ($meetingIds as $mid) {                       // $meetingIds is newest-first
            if ($agendaByMeeting->has($mid)) {
                $origin = $meetingIndex->get($mid);
                $previousAgenda = [
                    'origin' => $this->originStamp($origin),
                    'items' => $agendaByMeeting->get($mid)->map(fn (MeetingAgendaItem $a) => [
                        'item' => $a->item,
                        'description' => $a->description,
                        'owner_names' => $a->owner_names,
                        'duration_minutes' => $a->duration_minutes,
                        'priority' => $a->priority,
                    ])->values()->all(),
                ];
                break;
            }
        }

        // §11 previous-MOM stats: a compact "what happened last time" snapshot of
        // the single most recent prior meeting, so the organiser opens the new one
        // knowing the outstanding load. Read-only.
        $last = KickoffMeeting::forTenant($tenantId)
            ->withCount([
                'momItems as open_actions' => fn ($q) => $q->whereIn('status', MomActionStatus::OPEN_STATES),
                'issues as open_issues' => fn ($q) => $q->whereIn('status', MeetingIssueStatus::OPEN_STATES),
                'decisions as decisions_count',
                // Meeting.docx §11 shows the previous MOM as "12 Actions · 7 Closed
                // · 3 In Progress · 2 Overdue" — the split is the point, an open
                // count alone does not say whether last time went well.
                'momItems as total_actions',
                'momItems as closed_actions' => fn ($q) => $q->where('status', MomActionStatus::CLOSED),
                'momItems as in_progress_actions' => fn ($q) => $q->where('status', MomActionStatus::IN_PROGRESS),
                'momItems as overdue_actions' => fn ($q) => $q->whereIn('status', MomActionStatus::OPEN_STATES)
                    ->whereNotNull('target_date')->whereDate('target_date', '<', now()),
            ])
            ->find($priorMeetings->first()->id);

        $previousStats = $last ? [
            'origin' => $this->originStamp($last),
            'meeting_type_label' => $last->meeting_type_label,
            'status' => $last->status,
            'status_label' => $last->status_label,
            'mom_status' => $last->mom_status,
            'mom_status_label' => $last->mom_status_label,
            'acknowledged' => $last->acknowledged_at !== null,
            'open_actions' => (int) $last->open_actions,
            'open_issues' => (int) $last->open_issues,
            'decisions' => (int) $last->decisions_count,
            'total_actions' => (int) $last->total_actions,
            'closed_actions' => (int) $last->closed_actions,
            'in_progress_actions' => (int) $last->in_progress_actions,
            'overdue_actions' => (int) $last->overdue_actions,
        ] : null;

        return [
            'actions' => $actions,
            'issues' => $issues,
            'previous_agenda' => $previousAgenda,
            'previous_stats' => $previousStats,
        ];
    }

    /**
     * A subject's whole meeting history with rollup totals (Meeting.docx — the
     * per-vendor/project meeting record). Every meeting where the subject is the
     * primary, newest first, each with its own open-action/open-issue counts, plus
     * tenant-wide totals across them. Read-only; drives the history card.
     *
     * @return array{subject: array<string,mixed>, totals: array<string,int>, meetings: array<int,array<string,mixed>>}
     */
    public function subjectHistory(int $tenantId, string $subjectType, $subjectId): array
    {
        if (! KickoffSubject::isValid($subjectType)) {
            throw new BusinessException('Unknown subject type.');
        }
        $class = KickoffSubject::classFor($subjectType);
        $subject = $class::forTenant($tenantId)->find($subjectId);

        $meetings = KickoffMeeting::forTenant($tenantId)
            ->where('kickoffable_type', $class)
            ->where('kickoffable_id', (int) $subjectId)
            ->withCount([
                'momItems as open_actions' => fn ($q) => $q->whereIn('status', MomActionStatus::OPEN_STATES),
                'momItems as overdue_actions' => fn ($q) => $q->whereIn('status', MomActionStatus::OPEN_STATES)
                    ->whereNotNull('target_date')->whereDate('target_date', '<', now()),
                'issues as open_issues' => fn ($q) => $q->whereIn('status', MeetingIssueStatus::OPEN_STATES),
            ])
            ->orderByDesc('scheduled_at')
            ->get();

        // Meetings by type (Meeting.docx §17 — "Weekly Review — 14, HSE — 8 …").
        $byType = $meetings->groupBy('meeting_type')->map(fn ($group, $type) => [
            'type' => $type,
            'label' => config("meetings.types.{$type}", ucfirst(str_replace('_', ' ', (string) $type))),
            'count' => $group->count(),
        ])->sortByDesc('count')->values()->all();

        $totals = [
            'meetings' => $meetings->count(),
            'scheduled' => $meetings->where('status', Status::SCHEDULED)->count(),
            'delayed' => $meetings->where('status', Status::DELAYED)->count(),
            'completed' => $meetings->where('status', Status::COMPLETED)->count(),
            'cancelled' => $meetings->where('status', Status::CANCELLED)->count(),
            'open_actions' => (int) $meetings->sum('open_actions'),
            'overdue_actions' => (int) $meetings->sum('overdue_actions'),
            'open_issues' => (int) $meetings->sum('open_issues'),
            'awaiting_ack' => $meetings->where('status', Status::COMPLETED)->whereNull('acknowledged_at')->count(),
        ];

        return [
            'subject' => [
                'type' => $subjectType,
                'id' => (int) $subjectId,
                'name' => $subject ? KickoffSubject::nameOf($subject) : null,
            ],
            'totals' => $totals,
            'by_type' => $byType,
            'meetings' => $meetings->map(fn (KickoffMeeting $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'reference' => $m->reference,
                'meeting_type' => $m->meeting_type,
                'meeting_type_label' => $m->meeting_type_label,
                'status' => $m->status,
                'status_label' => $m->status_label,
                'scheduled_at' => optional($m->scheduled_at)->toIso8601String(),
                'mom_status' => $m->mom_status,
                'mom_status_label' => $m->mom_status_label,
                'is_acknowledged' => $m->is_acknowledged,
                'open_actions' => (int) $m->open_actions,
                'open_issues' => (int) $m->open_issues,
            ])->values()->all(),
        ];
    }

    /**
     * A project's meeting rollup (Meeting.docx §16) — every meeting tagged to the
     * project (via the soft project_id link), with the headline counts a PM sees on
     * the project: meetings, MOMs, actions (open/overdue), decisions. Keyed on the
     * project_id column, NOT the polymorphic subject (which is the vendor).
     *
     * @return array{totals: array<string,int>, meetings: array<int,array<string,mixed>>}
     */
    public function projectMeetings(int $tenantId, int $projectId): array
    {
        $meetings = KickoffMeeting::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->withCount([
                'momItems as open_actions' => fn ($q) => $q->whereIn('status', MomActionStatus::OPEN_STATES),
                'momItems as overdue_actions' => fn ($q) => $q->whereIn('status', MomActionStatus::OPEN_STATES)
                    ->whereNotNull('target_date')->whereDate('target_date', '<', now()),
                'momItems as total_actions',
                'decisions as decisions_count',
                'issues as open_issues' => fn ($q) => $q->whereIn('status', MeetingIssueStatus::OPEN_STATES),
            ])
            ->orderByDesc('scheduled_at')
            ->get();

        $totals = [
            'meetings' => $meetings->count(),
            'moms' => $meetings->whereNotNull('mom_path')->count(),
            'completed' => $meetings->where('status', Status::COMPLETED)->count(),
            'total_actions' => (int) $meetings->sum('total_actions'),
            'open_actions' => (int) $meetings->sum('open_actions'),
            'overdue_actions' => (int) $meetings->sum('overdue_actions'),
            'decisions' => (int) $meetings->sum('decisions_count'),
            'open_issues' => (int) $meetings->sum('open_issues'),
        ];

        return [
            'totals' => $totals,
            'meetings' => $meetings->map(fn (KickoffMeeting $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'reference' => $m->reference,
                'meeting_type' => $m->meeting_type,
                'meeting_type_label' => $m->meeting_type_label,
                'status' => $m->status,
                'status_label' => $m->status_label,
                'scheduled_at' => optional($m->scheduled_at)->toIso8601String(),
                'mom_status' => $m->mom_status,
                'mom_status_label' => $m->mom_status_label,
                'is_acknowledged' => $m->is_acknowledged,
                'open_actions' => (int) $m->open_actions,
                'decisions' => (int) $m->decisions_count,
                // The vendor this project meeting was with, for the row subtitle.
                'subject' => $m->subject,
            ])->values()->all(),
        ];
    }

    /** Where a carried item came from, for the "carried from …" label. */
    private function originStamp(?KickoffMeeting $m): array
    {
        return [
            'meeting_id' => $m?->id,
            'title' => $m?->title,
            'reference' => $m?->reference,
            'date' => optional($m?->scheduled_at)->toDateString(),
        ];
    }

    /**
     * Resolve and validate a carried-forward origin id from a form row.
     *
     * Returns the id only when it names a real record of the same kind in this
     * tenant that has NOT already been carried into another record — so a replayed
     * or duplicated payload can never fan one origin out across several meetings.
     */
    private function carriedFromId(array $row, string $modelClass, int $tenantId): ?int
    {
        $originId = $row['carried_from_id'] ?? null;
        if (! $originId) {
            return null;
        }

        $exists = $modelClass::where('tenant_id', $tenantId)->whereKey($originId)->exists();
        if (! $exists) {
            return null;
        }

        $already = $modelClass::where('tenant_id', $tenantId)
            ->where('carried_from_id', $originId)
            ->exists();

        return $already ? null : (int) $originId;
    }

    /**
     * Wholesale-replace the structured agenda. Mirrors replaceMomItems: an owner
     * given as an attendee id is verified against THIS meeting's attendees; a
     * free-typed owner name is resolved to an attendee where it matches and kept
     * verbatim otherwise. Empty rows (no item text) are dropped.
     */
    /**
     * Rebuild the meeting's agenda, returning a client-key → new-id map so the
     * MOM items and decisions saved afterwards can resolve their agenda link
     * (Meeting.docx §7). Agenda ids are not stable across a save (delete+recreate),
     * so the caller must always link by the stable client key the form assigns.
     *
     * @return array<string, int> keyed by the row's `client_key` (string)
     */
    private function replaceAgendaItems(KickoffMeeting $meeting, array $items, int $tenantId): array
    {
        $meeting->agendaItems()->delete();

        $validAttendeeIds = $meeting->attendees()->pluck('id')->all();
        $priorities = config('meetings.priorities', ['Low', 'Medium', 'High']);
        $order = 0;
        $map = [];

        foreach ($items as $item) {
            $topic = trim((string) ($item['item'] ?? ''));
            if ($topic === '') {
                continue;
            }

            $owner = $item['owner_attendee_id'] ?? null;
            if ($owner !== null && ! in_array((int) $owner, $validAttendeeIds, true)) {
                $owner = null;
            }

            $names = trim((string) ($item['owner_names'] ?? $item['owner'] ?? ''));
            if ($owner === null && $names !== '') {
                $first = trim(explode(',', $names)[0]);
                $match = $meeting->attendees->first(
                    fn ($a) => strcasecmp(trim((string) $a->name), $first) === 0
                );
                $owner = $match?->id;
            }

            $priority = $item['priority'] ?? null;
            if ($priority !== null && ! in_array($priority, $priorities, true)) {
                $priority = null;
            }

            $created = MeetingAgendaItem::create([
                'tenant_id' => $tenantId,
                'kickoff_meeting_id' => $meeting->id,
                'item' => $topic,
                'description' => $item['description'] ?? null,
                // Meeting.docx §7 — what was actually said under this item, and
                // what was settled. Previously both collapsed into the single
                // meeting-level `minutes` field, which broke the doc's
                // Agenda -> Discussion -> Decision -> Action chain at step two.
                'discussion' => $item['discussion'] ?? null,
                'decision' => $item['decision'] ?? null,
                'owner_attendee_id' => $owner,
                'owner_names' => $names !== '' ? $names : null,
                'duration_minutes' => $item['duration_minutes'] ?? null,
                'priority' => $priority,
                'sort_order' => $order++,
            ]);

            // Remember which fresh row this input represents so actions/decisions
            // in the same submission can point at it. Prefer the explicit
            // client_key; fall back to the prior server id (an edit that kept the
            // item sends its old id, which the MOM link also references).
            foreach ([$item['client_key'] ?? null, $item['id'] ?? null] as $k) {
                if ($k !== null && $k !== '') {
                    $map[(string) $k] = $created->id;
                }
            }
        }

        return $map;
    }

    private function replaceAttendees(KickoffMeeting $meeting, array $attendees, int $tenantId): void
    {
        $meeting->attendees()->delete();

        foreach ($attendees as $a) {
            $contact = null;
            $user = null;
            if (! empty($a['user_id'])) {
                $user = User::where('tenant_id', $tenantId)->find($a['user_id']);
            }

            if (! empty($a['vendor_contact_id'])) {
                $contact = VendorContact::forTenant($tenantId)->find($a['vendor_contact_id']);
                if (! $contact) {
                    throw new BusinessException('One of the selected vendor contacts does not exist.');
                }
            }

            KickoffAttendee::create([
                'tenant_id' => $tenantId,
                'kickoff_meeting_id' => $meeting->id,
                'vendor_contact_id' => $contact?->id,
                // Meeting.docx §5 — an internal participant picked from the staff
                // directory is a Sangoe identity, not a retyped name: it is what
                // makes the in-app notification, the auto-assignee on an action
                // and "my meetings" work at all. Verified against the tenant.
                'user_id' => $user?->id,
                'name' => $contact?->name ?? $user?->name ?? ($a['name'] ?? 'Unnamed attendee'),
                'email' => $contact?->email ?? $user?->email ?? ($a['email'] ?? null),
                'phone' => $contact?->phone ?? ($a['phone'] ?? null),
                'organisation' => $a['organisation'] ?? null,
                'role' => $a['role'] ?? null,
                'designation' => $contact?->designation ?? ($a['designation'] ?? null),
                // internal (own org) vs external (vendor/contractor) — Meeting.docx §5.
                'side' => in_array($a['side'] ?? null, ['internal', 'external'], true) ? $a['side'] : null,
                'attended' => ! empty($a['attended']),
            ]);
        }
    }

    /**
     * Write the meeting's full vendor set into kickoff_meeting_subjects.
     *
     * The primary is always row one and mirrors kickoffable_*, so a single query
     * on this table answers "who is on this meeting" — no union with the parent.
     * Each vendor gets its own ack token because acknowledgement is per vendor;
     * one shared token could not say which of them had signed off.
     *
     * Rebuilds the set rather than diffing, but PRESERVES any acknowledgement
     * already given: re-saving a meeting must not silently un-sign a vendor.
     */
    private function syncSubjects(KickoffMeeting $meeting, ?object $primary, array $extraIds, int $tenantId): void
    {
        if (! $primary) {
            return;   // a meeting with no vendor has nothing to mirror
        }

        $type = $primary::class;

        // Primary first, then the extras, de-duplicated and with the primary
        // never repeated — a vendor cannot attend the same meeting twice.
        $ids = collect([$primary->id])
            ->merge(array_map('intval', $extraIds))
            ->unique()->values();

        $existing = KickoffMeetingSubject::where('kickoff_meeting_id', $meeting->id)
            ->get()->keyBy(fn ($r) => $r->subject_type.'#'.$r->subject_id);

        foreach ($ids as $id) {
            // Tenant-scoped: an id from another tenant must not attach.
            $model = $type::forTenant($tenantId)->find($id);
            if (! $model) {
                continue;
            }

            $key = $type.'#'.$model->id;
            $row = $existing->get($key);

            if ($row) {
                $row->update(['is_primary' => $model->id === $primary->id]);
                $existing->forget($key);

                continue;
            }

            KickoffMeetingSubject::create([
                'tenant_id' => $tenantId,
                'kickoff_meeting_id' => $meeting->id,
                'subject_type' => $type,
                'subject_id' => $model->id,
                'is_primary' => $model->id === $primary->id,
                'ack_token' => Str::random(48),
            ]);
        }

        // Anything left was removed from the meeting. An ACKNOWLEDGED vendor is
        // kept: deleting it would erase a signature that was actually given.
        foreach ($existing as $stale) {
            if (! $stale->acknowledged_at) {
                $stale->delete();
            }
        }
    }

    private function resolveSubject(?string $type, $id, int $tenantId): ?object
    {
        if (! $type || ! $id) {
            return null;
        }
        if (! KickoffSubject::isValid($type)) {
            throw new BusinessException('Unknown subject type.');
        }

        $class = KickoffSubject::classFor($type);
        // forTenant, not find() — a meeting must never attach across tenants.
        $model = $class::forTenant($tenantId)->find($id);

        if (! $model) {
            throw new BusinessException(KickoffSubject::label($type).' not found.', 404);
        }

        return $model;
    }

    private function syncOnboardingPointer(?object $subject, int $meetingId): void
    {
        if ($subject instanceof TpvOnboarding) {
            $subject->update(['kickoff_meeting_id' => $meetingId]);
        } elseif ($subject instanceof Vendor) {
            $onboarding = TpvOnboarding::forTenant($subject->tenant_id)
                ->where('vendor_id', $subject->id)
                ->latest()
                ->first();
            if ($onboarding) {
                $onboarding->update(['kickoff_meeting_id' => $meetingId]);
            }
        }
    }

    private function defaultTitle(?object $subject): string
    {
        $name = KickoffSubject::nameOf($subject);

        return $name ? "Kickoff — {$name}" : 'Kickoff meeting';
    }
}
