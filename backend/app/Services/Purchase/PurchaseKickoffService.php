<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseKickoffDocument;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseKickoffMom;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Purchase\PurchaseKickoffRepository;
use App\Services\Notifications\NotificationService;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseMomDecision;
use App\Models\Purchase\PurchaseMomIssue;
use App\Support\Purchase\PurchaseKickoffStatus as Status;
use App\Support\Purchase\PurchaseMomApprovalStatus as MomStatus;
use App\Support\Purchase\PurchaseMomActionStatus;
use App\Support\Purchase\PurchaseMomIssueStatus;
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

    /**
     * The Meetings dashboard (Meeting.docx §14): today/upcoming, MOM backlog,
     * action backlog, decisions, by-type breakdown and the action closure rate.
     */
    public function dashboard(int $tenantId): array
    {
        $m = fn () => PurchaseKickoffMeeting::forTenant($tenantId);
        $actions = PurchaseMomActionItem::where('tenant_id', $tenantId)->get(['status', 'target_date']);
        $openActions = $actions->filter(fn ($a) => PurchaseMomActionStatus::isOpen($a->status));
        $closedActions = $actions->where('status', PurchaseMomActionStatus::CLOSED)->count();

        // A LIST of rows, not a keyed map. pluck() here produced
        // {"kickoff": 3} — a JSON object — and the dashboard card maps over
        // this, so it crashed with "list.map is not a function" the moment a
        // tenant had a meeting. The shared engine's shape is
        // [{type, label, count}, ...] and the same card renders both.
        $byType = $m()->get(['meeting_type'])
            ->groupBy('meeting_type')
            ->map(fn ($g, $type) => [
                'type'  => $type,
                'label' => \App\Support\Purchase\PurchaseMeetingTypeCatalog::label($type),
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->all();

        // Meetings per vendor, same shape as the shared engine's by_vendor.
        // Purchase meetings carry no project, so by_project is deliberately
        // absent — the card treats a missing key as "no data", which is true,
        // rather than showing an empty panel that looks like a loading failure.
        $byVendor = $m()->whereNotNull('purchase_vendor_id')
            ->with('vendor:id,company_name')->get(['id', 'purchase_vendor_id'])
            ->groupBy('purchase_vendor_id')
            ->map(fn ($g) => [
                'name'  => $g->first()->vendor?->company_name ?? 'Unknown',
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->all();

        return [
            'total'         => $m()->count(),
            'today'         => $m()->whereDate('scheduled_at', now()->toDateString())->count(),
            'upcoming'      => $m()->open()->whereNotNull('scheduled_at')->where('scheduled_at', '>=', now())->count(),
            'scheduled'     => $m()->where('status', Status::SCHEDULED)->count(),
            'delayed'       => $m()->where('status', Status::DELAYED)->count(),
            'completed'     => $m()->where('status', Status::COMPLETED)->count(),
            // MOM backlog: completed meetings whose minutes aren't distributed yet.
            'pending_mom'   => $m()->where('status', Status::COMPLETED)->where('mom_status', '!=', MomStatus::DISTRIBUTED)->count(),
            'overdue_mom'   => $m()->where('status', Status::COMPLETED)->where('mom_status', '!=', MomStatus::DISTRIBUTED)
                                    ->whereNotNull('completed_at')->where('completed_at', '<', now()->subDays(3))->count(),
            'awaiting_ack'  => $m()->where('status', Status::COMPLETED)->whereNull('acknowledged_at')->count(),
            'open_actions'    => $openActions->count(),
            'overdue_actions' => $openActions->filter(fn ($a) => $a->target_date && $a->target_date->isPast())->count(),
            'decisions_active' => PurchaseMomDecision::where('tenant_id', $tenantId)->where('status', 'Active')->count(),
            'open_issues'   => PurchaseMomIssue::where('tenant_id', $tenantId)
                                    ->whereIn('status', PurchaseMomIssueStatus::OPEN_STATES)->count(),
            'closure_rate'  => $actions->count() > 0 ? (int) round($closedActions / $actions->count() * 100) : 0,
            // Counters the shared dashboard reads that Purchase was not
            // reporting — they rendered as blank tiles rather than zeroes.
            'total_actions'  => $actions->count(),
            'closed_actions' => $closedActions,
            'by_type'        => $byType,
            'by_vendor'      => $byVendor,
        ];
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
            // Born a draft — saving records the meeting but tells nobody. Going
            // live (invitations) happens on Publish (Draft → Scheduled).
            'status'                 => Status::DRAFT,
            'priority'               => $data['priority'] ?? null,
            'confidentiality'        => $data['confidentiality'] ?? null,
            'chairperson'            => $data['chairperson'] ?? null,
            'coordinator'            => $data['coordinator'] ?? null,
            'organizer'              => $data['organizer'] ?? null,
            'department'             => $data['department'] ?? null,
            'client_name'            => $data['client_name'] ?? null,
            'scheduled_at'           => $data['scheduled_at'] ?? null,
            'end_at'                 => $data['end_at'] ?? null,
            // Duration is derived from start+end, never taken from the client.
            'duration_minutes'       => $this->computeDuration($data['scheduled_at'] ?? null, $data['end_at'] ?? null),
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

        $wasPublished = Status::isPublished($meeting->status);
        $before = [
            'scheduled_at' => optional($meeting->scheduled_at)->toDateTimeString(),
            'end_at' => optional($meeting->end_at)->toDateTimeString(),
            'mode' => $meeting->mode,
            'location' => $meeting->location,
        ];
        $effStart = $data['scheduled_at'] ?? $meeting->scheduled_at;
        $effEnd = $data['end_at'] ?? $meeting->end_at;

        $meeting->update(array_filter([
            'title'             => $data['title'] ?? null,
            'meeting_type'      => $data['meeting_type'] ?? null,
            'reference'         => $data['reference'] ?? null,
            'agenda'            => $data['agenda'] ?? null,
            'priority'          => $data['priority'] ?? null,
            'confidentiality'   => $data['confidentiality'] ?? null,
            'chairperson'       => $data['chairperson'] ?? null,
            'coordinator'       => $data['coordinator'] ?? null,
            'organizer'         => $data['organizer'] ?? null,
            'department'        => $data['department'] ?? null,
            'client_name'       => $data['client_name'] ?? null,
            'scheduled_at'      => $data['scheduled_at'] ?? null,
            'end_at'            => $data['end_at'] ?? null,
            // Always derived from the effective start+end — never client-sent.
            'duration_minutes'  => $this->computeDuration($effStart, $effEnd),
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

        // A published meeting whose time/place/roster changed re-notifies the roster.
        $meeting->refresh();
        $scheduleChanged = $before !== [
            'scheduled_at' => optional($meeting->scheduled_at)->toDateTimeString(),
            'end_at' => optional($meeting->end_at)->toDateTimeString(),
            'mode' => $meeting->mode,
            'location' => $meeting->location,
        ];
        if ($wasPublished && $meeting->scheduled_at && ($scheduleChanged || array_key_exists('participants', $data))) {
            $this->notifyParticipants($meeting->fresh('participants'), true);
        }

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

        // Draft → Scheduled is "Publish": needs a time to invite people to.
        $isPublishing = $meeting->status === Status::DRAFT && $to === Status::SCHEDULED;
        if ($isPublishing && ! ($data['scheduled_at'] ?? $meeting->scheduled_at)) {
            throw new BusinessException('Set the meeting date and time before publishing.');
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

        // Publishing sends the invitation to the roster (mandatory) and shares
        // the join link. Reminders read the live scheduled_at each run.
        if ($isPublishing) {
            $this->notifyParticipants($meeting->fresh('participants'), false);
        }

        $verb = $isPublishing
            ? 'published'
            : (['Delayed' => 'delayed', 'Completed' => 'completed', 'Cancelled' => 'cancelled', 'Scheduled' => 'rescheduled'][$to] ?? 'updated');
        $meeting->recordAudit($verb, $actor, ucfirst($verb).($data['delay_reason'] ?? null ? ": {$data['delay_reason']}" : ''));
        Log::channel('purchase')->info('Purchase kickoff '.$verb, [
            'meeting_id' => $meeting->id, 'actor_id' => $actor->id, 'status' => $to,
        ]);

        return $this->find($meeting->id, $actor->tenant_id);
    }

    /**
     * Derive the meeting duration (minutes) from start+end. Returns null when
     * either bound is missing or the window is non-positive.
     */
    private function computeDuration($scheduledAt, $endAt): ?int
    {
        if (empty($scheduledAt) || empty($endAt)) {
            return null;
        }
        try {
            $minutes = \Illuminate\Support\Carbon::parse($scheduledAt)
                ->diffInMinutes(\Illuminate\Support\Carbon::parse($endAt), false);
        } catch (\Throwable) {
            return null;
        }

        return $minutes > 0 ? (int) $minutes : null;
    }

    /**
     * Invitation e-mail to every participant with an address (sent on publish and
     * when a published meeting's time/place/roster changes). Carries the join link.
     */
    private function notifyParticipants(PurchaseKickoffMeeting $meeting, bool $isUpdate): void
    {
        $meeting->loadMissing('participants', 'vendor');

        $vendorName = $meeting->vendor?->company_name;
        $when = $meeting->scheduled_at ? $meeting->scheduled_at->format('d M Y, g:i A') : 'a date to be confirmed';
        $where = $meeting->mode === 'online'
            ? ($meeting->meeting_link ? " Join link: {$meeting->meeting_link}" : '')
            : ($meeting->location ? " at {$meeting->location}" : '');
        $subject = ($isUpdate ? 'Updated: ' : 'Invitation: ')."{$meeting->title}";
        $body = ($isUpdate ? 'The details of this meeting have changed. ' : '')
            ."You are invited to the kickoff meeting \"{$meeting->title}\""
            .($vendorName ? " with {$vendorName}" : '')
            .", scheduled for {$when}.{$where}";

        foreach ($meeting->participants as $participant) {
            if (! $participant->email) {
                continue;
            }
            $this->notifications->email(
                $participant->email, $subject, $body,
                ['category' => 'Purchase', 'purchase_kickoff_meeting_id' => $meeting->id],
                $meeting->tenant_id,
            );
        }
    }

    /** The vendor's most recent earlier meeting (for continuity — Meeting.docx §11). */
    public function previousMeeting(PurchaseKickoffMeeting $meeting): ?PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::forTenant($meeting->tenant_id)
            ->where('purchase_vendor_id', $meeting->purchase_vendor_id)
            ->where('id', '!=', $meeting->id)
            ->latest('scheduled_at')->latest('id')
            ->first();
    }

    /** Previous-MOM summary: prior meeting + its action/issue rollup (§11). */
    public function previousSummary(PurchaseKickoffMeeting $meeting): array
    {
        $prev = $this->previousMeeting($meeting);
        if (! $prev) {
            return ['previous' => null];
        }
        $actions = PurchaseMomActionItem::where('purchase_kickoff_meeting_id', $prev->id)->get();
        $issues  = PurchaseMomIssue::where('purchase_kickoff_meeting_id', $prev->id)->get();

        return [
            'previous' => [
                'id' => $prev->id, 'reference' => $prev->reference, 'meeting_no' => $prev->meeting_no,
                'title' => $prev->title, 'scheduled_at' => $prev->scheduled_at,
            ],
            'actions' => [
                'total'   => $actions->count(),
                'closed'  => $actions->where('status', PurchaseMomActionStatus::CLOSED)->count(),
                'open'    => $actions->filter(fn ($a) => PurchaseMomActionStatus::isOpen($a->status))->count(),
                'overdue' => $actions->filter(fn ($a) => $a->is_overdue)->count(),
            ],
            'issues' => [
                'total' => $issues->count(),
                'open'  => $issues->filter(fn ($i) => PurchaseMomIssueStatus::isOpen($i->status))->count(),
            ],
        ];
    }

    /**
     * Carry the previous meeting's OPEN actions and issues into this meeting
     * (Meeting.docx §11 "Carry Forward Open Items") — creating fresh, tracked
     * records here so continuity is preserved without mutating the old meeting.
     */
    public function carryForwardOpenItems(PurchaseKickoffMeeting $meeting, User $actor): array
    {
        $prev = $this->previousMeeting($meeting);
        if (! $prev) {
            throw new BusinessException('No previous meeting found for this vendor to carry items from.');
        }

        $actionSvc = app(PurchaseMomActionService::class);
        $issueSvc  = app(PurchaseMomIssueService::class);
        $actions = 0;
        $issues  = 0;

        foreach (PurchaseMomActionItem::where('purchase_kickoff_meeting_id', $prev->id)->with('responsible:id,name')->get() as $a) {
            if (! PurchaseMomActionStatus::isOpen($a->status)) {
                continue;
            }
            $actionSvc->create($meeting, [
                'description'       => $a->description.' (carried from '.($prev->meeting_no ?: $prev->reference).')',
                'responsible_names' => $a->responsible_names ?: ($a->responsible?->name ?: 'Carried forward'),
                'responsible_org'   => $a->responsible_org,
                'priority'          => $a->priority,
                'target_date'       => $a->target_date?->toDateString(),
            ], $actor);
            $actions++;
        }

        foreach (PurchaseMomIssue::where('purchase_kickoff_meeting_id', $prev->id)->get() as $i) {
            if (! PurchaseMomIssueStatus::isOpen($i->status)) {
                continue;
            }
            $issueSvc->create($meeting, [
                'title'       => $i->title,
                'description' => trim(($i->description ? $i->description."\n" : '').'(carried from '.($prev->meeting_no ?: $prev->reference).')'),
                'category'    => $i->category,
                'severity'    => $i->severity,
            ], $actor);
            $issues++;
        }

        $meeting->recordAudit('items_carried_forward', $actor, "Carried forward {$actions} action(s) and {$issues} issue(s) from ".($prev->meeting_no ?: $prev->reference));

        return ['from' => $prev->meeting_no ?: $prev->reference, 'actions' => $actions, 'issues' => $issues];
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
        // The structured registers are loaded too — the MOM prints them, and a
        // lazy-load inside the Blade would be a query per row.
        $meeting->loadMissing(
            'participants', 'vendor', 'creator',
            'agendaItems.owner', 'actionItems.responsible', 'momDecisions.decidedBy', 'momIssues.owner',
        );

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
    /**
     * Distribute the approved minutes to the vendor. Acknowledgement was removed
     * (parity with the shared engine): the vendor reads the approved minutes in
     * their logged-in portal — no bearer token, no sign-off step.
     */
    public function distributeMom(PurchaseKickoffMeeting $meeting, User $actor): PurchaseKickoffMeeting
    {
        if ($meeting->status !== Status::COMPLETED) {
            throw new BusinessException('Complete the meeting before sending its minutes to the vendor.');
        }
        if (! $meeting->currentMom()->exists()) {
            throw new BusinessException('Generate or upload the MOM document before sending it.');
        }
        if (! MomStatus::isDistributable($meeting->mom_status)) {
            throw new BusinessException('The minutes must be approved before they can be sent to the vendor.');
        }

        $meeting->update([
            'mom_status'         => MomStatus::DISTRIBUTED,
            'mom_distributed_at' => $meeting->mom_distributed_at ?? now(),
            'mom_distributed_by' => $meeting->mom_distributed_by ?? $actor->id,
        ]);
        $this->sendMomNotifications($meeting);
        $meeting->recordAudit('mom_distributed', $actor, 'Minutes distributed to the vendor');

        return $this->find($meeting->id, $actor->tenant_id);
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
            // 6-state attendance (§6): status drives the boolean; a bare boolean still works.
            $status = $row['attendance_status'] ?? null;
            if ($status && in_array($status, PurchaseKickoffParticipant::ATTENDANCE, true)) {
                $attended = in_array($status, PurchaseKickoffParticipant::ATTENDING, true);
                $participant->update(['attendance_status' => $status, 'attended' => $attended]);
            } else {
                $attended = ! empty($row['attended']);
                $participant->update(['attended' => $attended, 'attendance_status' => $attended ? 'Present' : 'Absent']);
            }
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
            $result = $this->notifications->email($participant->email, $subject, $body,
                ['category' => 'Purchase', 'purchase_kickoff_meeting_id' => $meeting->id]);
            $email[$result] = ($email[$result] ?? 0) + 1;
        }

        $phone    = $meeting->vendor?->phone;
        $whatsapp = $this->notifications->whatsapp($phone, $body,
            ['category' => 'Purchase', 'purchase_kickoff_meeting_id' => $meeting->id]);
        $sms      = $this->notifications->sms($phone, $body, ['category' => 'Purchase', 'purchase_kickoff_meeting_id' => $meeting->id]);

        $meeting->recordAudit('reminder_sent', $actor, "Reminder sent — email: {$email['sent']} sent, WhatsApp/SMS queued");

        return [
            'recipients' => $meeting->participants->count(),
            'email'      => $email,
            'whatsapp'   => $whatsapp,
            'sms'        => $sms,
        ];
    }

    /**
     * Automatic reminders — fires reminder e-mails as each configured lead time
     * before a published meeting's start is reached (config meetings.reminder_offsets_minutes,
     * default 24h + 1h). Idempotent via reminders_sent. Mirrors the shared engine.
     */
    public function runDueReminders(): int
    {
        $offsets = collect(config('meetings.reminder_offsets_minutes', [1440, 60]))
            ->map(fn ($m) => (int) $m)->filter(fn ($m) => $m > 0)->unique()->sortDesc()->values();
        if ($offsets->isEmpty()) {
            return 0;
        }

        $now = now();
        $meetings = PurchaseKickoffMeeting::whereIn('status', [Status::SCHEDULED, Status::DELAYED])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', (clone $now)->addMinutes($offsets->max()))
            ->with('participants', 'vendor')
            ->get();

        $sent = 0;
        foreach ($meetings as $meeting) {
            $already = collect($meeting->reminders_sent ?? [])->map(fn ($k) => (string) $k);
            $minutesUntil = (int) round($now->diffInMinutes($meeting->scheduled_at, false));
            $due = $offsets->filter(fn ($o) => ! $already->contains((string) $o) && $minutesUntil <= $o)->values();
            if ($due->isEmpty()) {
                continue;
            }
            $this->dispatchAutoReminder($meeting, (int) $due->min());
            $meeting->reminders_sent = $already->merge($due->map(fn ($o) => (string) $o))->unique()->values()->all();
            $meeting->saveQuietly();
            $sent++;
        }

        return $sent;
    }

    private function dispatchAutoReminder(PurchaseKickoffMeeting $meeting, int $offsetMinutes): void
    {
        $vendorName = $meeting->vendor?->company_name;
        $when = $meeting->scheduled_at ? $meeting->scheduled_at->format('d M Y, g:i A') : 'a date to be confirmed';
        $lead = $offsetMinutes >= 1440 ? (intdiv($offsetMinutes, 1440).' day(s)')
            : ($offsetMinutes >= 60 ? (intdiv($offsetMinutes, 60).' hour(s)') : $offsetMinutes.' minutes');
        $where = $meeting->mode === 'online'
            ? ($meeting->meeting_link ? " Join link: {$meeting->meeting_link}" : '')
            : ($meeting->location ? " at {$meeting->location}" : '');
        $subject = "Reminder: {$meeting->title} in {$lead}";
        $body = "This is a reminder that the meeting \"{$meeting->title}\""
            .($vendorName ? " with {$vendorName}" : '')
            ." is scheduled for {$when} (in about {$lead}).{$where}";

        foreach ($meeting->participants as $participant) {
            if (! $participant->email) {
                continue;
            }
            $this->notifications->email(
                $participant->email, $subject, $body,
                ['category' => 'Purchase', 'purchase_kickoff_meeting_id' => $meeting->id],
                $meeting->tenant_id,
            );
        }
        Log::channel('purchase')->info('Purchase kickoff auto-reminder sent', [
            'meeting_id' => $meeting->id, 'offset_minutes' => $offsetMinutes,
        ]);
    }

    /* ── Labelled supporting documents (multiple upload) ────────────────── */

    /**
     * @param  array<int, \Illuminate\Http\UploadedFile|null>  $files
     * @param  array<int, string|null>  $labels
     */
    public function uploadDocuments(PurchaseKickoffMeeting $meeting, array $files, array $labels, User $actor, ?int $actionItemId = null)
    {
        $stored = collect();
        foreach (array_values($files) as $i => $file) {
            if (! $file) {
                continue;
            }
            $label = trim((string) ($labels[$i] ?? '')) ?: $file->getClientOriginalName();
            $path = $file->store("tenant-{$meeting->tenant_id}/meeting-{$meeting->id}/documents", self::DISK);

            $stored->push(PurchaseKickoffDocument::create([
                'tenant_id' => $meeting->tenant_id,
                'purchase_kickoff_meeting_id' => $meeting->id,
                'purchase_mom_action_item_id' => $actionItemId,
                'label' => mb_substr($label, 0, 160),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $actor->id,
            ]));
        }

        if ($stored->isNotEmpty()) {
            $meeting->recordAudit('documents_uploaded', $actor, $stored->count().' document(s) attached');
        }

        return $stored;
    }

    public function deleteDocument(PurchaseKickoffMeeting $meeting, PurchaseKickoffDocument $doc, User $actor): void
    {
        if ((int) $doc->purchase_kickoff_meeting_id !== (int) $meeting->id) {
            throw new BusinessException('Document not found.', 404);
        }
        if ($doc->path && Storage::disk(self::DISK)->exists($doc->path)) {
            Storage::disk(self::DISK)->delete($doc->path);
        }
        $label = $doc->label;
        $doc->delete();
        $meeting->recordAudit('document_deleted', $actor, 'Document removed: '.$label);
    }

    /**
     * Push a MOM action into the Task module as a real Task.
     *
     * An action that lives only in the minutes gets chased only by whoever
     * re-reads them. As a Task it lands in someone's list with a due date and
     * shows on the vendor's Tasks tab, which is the whole point of raising it.
     *
     * Mirrors Shared\KickoffMeetingService::pushActionToTask; the differences
     * are Purchase's own: the subject link is `purchase_vendor` (TaskService
     * already accepts that rel_type), and the assignee is resolved through a
     * participant rather than an attendee.
     */
    public function pushActionToTask(PurchaseMomActionItem $item, User $actor): PurchaseMomActionItem
    {
        // The link column is what makes this idempotent — without the guard a
        // second click silently creates a duplicate task for the same action.
        if ($item->task_id) {
            throw new BusinessException('This action is already linked to a task.');
        }

        $meeting = $item->meeting()->with(['vendor', 'participants'])->first();

        $title = trim(strip_tags((string) $item->description));
        if ($title === '') {
            throw new BusinessException('Add a description before creating a task from this action.');
        }
        $title = mb_substr($title, 0, 200);

        // Link the task to the vendor when the meeting is about one, so it shows
        // on that vendor's Tasks tab.
        $relType = 'standalone';
        $relId = null;
        if ($meeting?->purchase_vendor_id) {
            $relType = 'purchase_vendor';
            $relId = (int) $meeting->purchase_vendor_id;
        }

        // The responsible participant's login becomes the assignee, when they
        // have one — participants are often external and carry no user account.
        $assigneeIds = [];
        if ($item->responsible_participant_id) {
            $uid = $meeting?->participants->firstWhere('id', $item->responsible_participant_id)?->user_id;
            if ($uid) {
                $assigneeIds[] = $uid;
            }
        }

        $priorityMap = ['Low' => 'low', 'Medium' => 'medium', 'High' => 'high', 'Urgent' => 'urgent'];
        $priority = $priorityMap[$item->priority] ?? 'medium';

        $backlink = 'From meeting '.($meeting?->meeting_no ?: ('#'.$meeting?->id))
            .' · action '.$item->action_ref;

        $task = app(\App\Services\Task\TaskService::class)->create([
            'name' => $title,
            'description' => (string) $item->description."\n\n<p><em>{$backlink}</em></p>",
            'priority' => $priority,
            'start_date' => now()->toDateString(),
            'due_date' => optional($item->target_date)->toDateString(),
            'rel_type' => $relType,
            'rel_id' => $relId,
            'assignee_ids' => $assigneeIds,
        ], $actor->tenant_id, $actor->id);

        $item->forceFill(['task_id' => $task->id])->save();

        $meeting?->recordAudit('action_pushed_to_task', $actor,
            "Action {$item->action_ref} pushed to task #{$task->id}");

        Log::channel('purchase')->info('Purchase meeting action pushed to task', [
            'action_id' => $item->id, 'task_id' => $task->id, 'tenant_id' => $actor->tenant_id,
        ]);

        return $item->fresh();
    }

    /**
     * Every meeting held for one vendor, newest first.
     *
     * The vendor's meeting history — what the workspace links to and what the
     * create screen shows so a recurring meeting is planned against the last
     * one rather than from scratch.
     */
    public function vendorHistory(int $tenantId, ?int $vendorId = null, int $limit = 50): array
    {
        $q = PurchaseKickoffMeeting::where('tenant_id', $tenantId)
            ->with('vendor:id,company_name')
            ->orderByDesc('scheduled_at');

        if ($vendorId) {
            $q->where('purchase_vendor_id', $vendorId);
        }

        return $q->limit($limit)->get()->map(fn ($m) => [
            'id' => $m->id,
            'meeting_no' => $m->meeting_no ?: $m->reference,
            'title' => $m->title,
            'meeting_type' => $m->meeting_type,
            'meeting_type_label' => $m->meeting_type_label,
            'status' => $m->status,
            'status_label' => $m->status_label,
            'scheduled_at' => $m->scheduled_at,
            'vendor' => $m->vendor?->company_name,
            'purchase_vendor_id' => $m->purchase_vendor_id,
            'mom_status' => $m->mom_status,
        ])->all();
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
        $body = "The Minutes of Meeting (MOM) for \"{$meeting->title}\" have been approved and are now available.\n\nPlease log into the Purchase Vendor Portal to view the minutes.";

        if ($email) {
            $this->notifications->email($email, $subjectTitle, $body,
                ['category' => 'Purchase', 'purchase_kickoff_meeting_id' => $meeting->id],
                $meeting->tenant_id);
        }
        if ($phone) {
            $this->notifications->whatsapp($phone, $body, ['category' => 'Purchase', 'purchase_kickoff_meeting_id' => $meeting->id]);
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
                'phone'                       => $p['phone'] ?? null,
                'organisation'                => $p['organisation'] ?? null,
                'designation'                 => $p['designation'] ?? null,
                'role'                        => $p['role'] ?? null,
                'side'                        => $p['side'] ?? null,
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
