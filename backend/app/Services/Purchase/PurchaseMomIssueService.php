<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Purchase\PurchaseMomIssue;
use App\Models\User;
use App\Support\Purchase\PurchaseMomIssueStatus as IssueStatus;
use App\Services\Purchase\PurchaseApprovalRequestService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase MOM issue register (Sangoe TPV §9): issues raised in a meeting's
 * minutes, tracked to resolution and convertible to an NCR or a CAPA. Purchase-
 * owned: touches only purchase_mom_issues, and delegates conversion to the
 * existing Purchase NCR/CAPA services (never the shared/TPV meeting_issues).
 */
class PurchaseMomIssueService
{
    public function __construct(
        private PurchaseNcrService $ncrs,
        private PurchaseCapaService $capas,
    ) {}

    // PurchaseApprovalRequestService is resolved lazily in convertToApproval().

    /** All issues for a meeting, ordered for display. */
    public function forMeeting(PurchaseKickoffMeeting $meeting): Collection
    {
        return $meeting->momIssues()
            ->with(['owner:id,name'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    public function create(PurchaseKickoffMeeting $meeting, array $data, User $actor): PurchaseMomIssue
    {
        $participantId = $this->resolveParticipant($meeting, $data['owner_participant_id'] ?? null);

        $issue = PurchaseMomIssue::create([
            'tenant_id'                   => $meeting->tenant_id,
            'purchase_kickoff_meeting_id' => $meeting->id,
            'issue_ref'                   => $this->nextIssueRef($meeting->tenant_id),
            'title'                       => $data['title'],
            'description'                 => $data['description'] ?? null,
            'category'                    => $data['category'] ?? null,
            'severity'                    => $data['severity'] ?? null,
            'owner_participant_id'        => $participantId,
            'owner_names'                 => $data['owner_names'] ?? null,
            'due_date'                    => $data['due_date'] ?? null,
            'status'                      => IssueStatus::OPEN,
            'sort_order'                  => $data['sort_order'] ?? ($meeting->momIssues()->max('sort_order') + 1),
        ]);

        $meeting->recordAudit('issue_added', $actor, "Issue {$issue->issue_ref} raised", ['issue_id' => $issue->id]);
        Log::channel('purchase')->info('Purchase MOM issue raised', [
            'meeting_id' => $meeting->id, 'issue_id' => $issue->id, 'actor_id' => $actor->id,
        ]);

        return $issue->fresh(['owner:id,name']);
    }

    public function update(PurchaseMomIssue $issue, array $data, User $actor): PurchaseMomIssue
    {
        $changes = array_filter([
            'title'       => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'category'    => $data['category'] ?? null,
            'severity'    => $data['severity'] ?? null,
            'owner_names' => $data['owner_names'] ?? null,
            'due_date'    => $data['due_date'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('owner_participant_id', $data)) {
            $changes['owner_participant_id'] = $this->resolveParticipant($issue->meeting, $data['owner_participant_id']);
        }

        $issue->update($changes);
        $issue->meeting?->recordAudit('issue_updated', $actor, "Issue {$issue->issue_ref} updated", ['issue_id' => $issue->id]);

        return $issue->fresh(['owner:id,name']);
    }

    /** Move an issue along its lifecycle (guarded by the transition map). */
    public function progress(PurchaseMomIssue $issue, string $to, User $actor): PurchaseMomIssue
    {
        if (! IssueStatus::canTransition($issue->status, $to)) {
            throw new BusinessException(
                'Cannot move a '.IssueStatus::label($issue->status).' issue to '.IssueStatus::label($to).'.'
            );
        }

        $issue->update(['status' => $to]);
        $issue->meeting?->recordAudit('issue_progressed', $actor, "Issue {$issue->issue_ref} → ".IssueStatus::label($to), [
            'issue_id' => $issue->id,
        ]);

        return $issue->fresh(['owner:id,name']);
    }

    /**
     * Escalate an issue to a Purchase NCR. Idempotent — an already-converted issue
     * is refused. The new NCR inherits the issue's title/description/severity and
     * the meeting's vendor; the issue is stamped with the link and moved to
     * In Progress.
     */
    public function convertToNcr(PurchaseMomIssue $issue, User $actor): PurchaseMomIssue
    {
        $this->assertNotConverted($issue);
        $meeting = $issue->meeting;

        $ncr = $this->ncrs->create([
            'purchase_vendor_id' => $meeting?->purchase_vendor_id,
            'title'              => $issue->title,
            'finding'            => $issue->description,
            'severity'           => IssueStatus::ncrSeverityFor($issue->severity),
            'due_date'           => $issue->due_date,
            'source_type'        => PurchaseMomIssue::class,
            'source_id'          => $issue->id,
        ], (int) $issue->tenant_id, (int) $actor->id);

        return $this->stampConversion($issue, 'NCR', $ncr->reference, $ncr->id, $actor);
    }

    /**
     * Escalate an issue to a central Approval-register entry (Meeting.docx §10).
     * Idempotent. The issue moves to In Progress and links to the PAPR reference.
     */
    public function convertToApproval(PurchaseMomIssue $issue, User $actor): PurchaseMomIssue
    {
        $this->assertNotConverted($issue);
        $meeting = $issue->meeting;

        $approval = app(PurchaseApprovalRequestService::class)->raise([
            'approval_type'      => 'other',
            'title'              => 'Approval for '.$issue->issue_ref.' — '.$issue->title,
            'description'        => $issue->description,
            'purchase_vendor_id' => $meeting?->purchase_vendor_id,
            'subject_type'       => PurchaseMomIssue::class,
            'subject_id'         => $issue->id,
            'priority'           => $this->approvalPriorityFor($issue->severity),
        ], (int) $issue->tenant_id, (int) $actor->id);

        return $this->stampConversion($issue, 'Approval', $approval->reference, $approval->id, $actor);
    }

    /**
     * Escalate an issue to a Purchase CAPA (Corrective), linked back to the issue
     * (source_kind=meeting_issue). Idempotent. The issue moves to In Progress.
     */
    public function convertToCapa(PurchaseMomIssue $issue, User $actor): PurchaseMomIssue
    {
        $this->assertNotConverted($issue);
        $meeting = $issue->meeting;

        $capa = $this->capas->raiseFrom('meeting_issue', $issue->id, [
            'title'    => 'Corrective action for '.$issue->issue_ref.' — '.$issue->title,
            'type'     => 'Corrective',
            'priority' => $this->capaPriorityFor($issue->severity),
            'due_date' => $issue->due_date,
        ], (int) $issue->tenant_id, (int) $actor->id, $meeting?->purchase_vendor_id);

        return $this->stampConversion($issue, 'CAPA', $capa->reference, $capa->id, $actor);
    }

    public function delete(PurchaseMomIssue $issue, User $actor): void
    {
        $issue->meeting?->recordAudit('issue_deleted', $actor, "Issue {$issue->issue_ref} deleted", ['issue_id' => $issue->id]);
        $issue->delete();
    }

    /* ── internals ─────────────────────────────────────────────── */

    private function assertNotConverted(PurchaseMomIssue $issue): void
    {
        if ($issue->converted_to) {
            throw new BusinessException("This issue has already been converted to {$issue->converted_to} {$issue->converted_ref}.");
        }
    }

    /**
     * Escalate an issue into a real Task. Idempotent, like the other three.
     *
     * NCR/CAPA/Approval are the heavyweight escalations; plenty of meeting
     * issues are simply "someone needs to go and do this", and without a task
     * target those were either forced into an NCR they did not warrant or left
     * in the minutes to be forgotten.
     */
    public function convertToTask(PurchaseMomIssue $issue, User $actor): PurchaseMomIssue
    {
        $this->assertNotConverted($issue);
        $meeting = $issue->meeting;

        $title = trim((string) $issue->title);
        if ($title === '') {
            throw new BusinessException('Add a title before creating a task from this issue.');
        }

        // Link to the vendor when the meeting has one, so the task shows on that
        // vendor's Tasks tab. TaskService already accepts this rel_type.
        $relType = $meeting?->purchase_vendor_id ? 'purchase_vendor' : 'standalone';
        $relId = $meeting?->purchase_vendor_id ? (int) $meeting->purchase_vendor_id : null;

        $backlink = 'From meeting '.($meeting?->meeting_no ?: ('#'.$meeting?->id))
            .' · issue '.$issue->issue_ref;

        $task = app(\App\Services\Task\TaskService::class)->create([
            'name' => mb_substr($title, 0, 200),
            'description' => (string) $issue->description."\n\n<p><em>{$backlink}</em></p>",
            'priority' => $this->taskPriorityFor($issue->severity),
            'start_date' => now()->toDateString(),
            'due_date' => optional($issue->due_date)->toDateString(),
            'rel_type' => $relType,
            'rel_id' => $relId,
        ], (int) $issue->tenant_id, (int) $actor->id);

        return $this->stampConversion($issue, 'Task', '#'.$task->id, $task->id, $actor);
    }

    /** Issue severity → task priority (the Task module uses lowercase names). */
    private function taskPriorityFor(?string $severity): string
    {
        return match ($severity) {
            'Critical' => 'urgent',
            'High' => 'high',
            'Low' => 'low',
            default => 'medium',
        };
    }

    private function stampConversion(PurchaseMomIssue $issue, string $to, ?string $ref, ?int $id, User $actor): PurchaseMomIssue
    {
        $issue->update([
            'converted_to'  => $to,
            'converted_ref' => $ref,
            'converted_id'  => $id,
            'status'        => IssueStatus::isOpen($issue->status) && $issue->status === IssueStatus::OPEN
                ? IssueStatus::IN_PROGRESS
                : $issue->status,
        ]);
        $issue->meeting?->recordAudit('issue_converted', $actor, "Issue {$issue->issue_ref} converted to {$to} {$ref}", [
            'issue_id' => $issue->id, 'converted_to' => $to, 'converted_ref' => $ref,
        ]);
        Log::channel('purchase')->info('Purchase MOM issue converted', [
            'issue_id' => $issue->id, 'to' => $to, 'ref' => $ref,
        ]);

        return $issue->fresh(['owner:id,name']);
    }

    /** Issue severity → CAPA priority (both use Low/Medium/High/Critical). */
    private function capaPriorityFor(?string $severity): string
    {
        return in_array($severity, ['Low', 'Medium', 'High', 'Critical'], true) ? $severity : 'Medium';
    }

    /** Issue severity → approval priority (Low/Medium/High/Urgent). */
    private function approvalPriorityFor(?string $severity): string
    {
        return ['Low' => 'Low', 'Medium' => 'Medium', 'High' => 'High', 'Critical' => 'Urgent'][$severity] ?? 'Medium';
    }

    /** Verify a chosen participant belongs to this meeting; null clears it. */
    private function resolveParticipant(?PurchaseKickoffMeeting $meeting, $participantId): ?int
    {
        if (empty($participantId) || ! $meeting) {
            return null;
        }
        $exists = PurchaseKickoffParticipant::where('purchase_kickoff_meeting_id', $meeting->id)
            ->whereKey($participantId)->exists();
        if (! $exists) {
            throw new BusinessException('The selected owner is not a participant of this meeting.');
        }

        return (int) $participantId;
    }

    /** ISS-YYYY-NNNN, sequential per tenant per year. */
    private function nextIssueRef(int $tenantId): string
    {
        $year = now()->year;
        $count = PurchaseMomIssue::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('issue_ref', 'like', "ISS-{$year}-%")
            ->count();

        return sprintf('ISS-%d-%04d', $year, $count + 1);
    }
}
