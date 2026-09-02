<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseMomDecision;
use App\Models\Purchase\PurchaseMomIssue;
use App\Support\Purchase\PurchaseMomActionStatus as ActionStatus;
use App\Support\Purchase\PurchaseMomIssueStatus as IssueStatus;

/**
 * The three cross-meeting registers for Purchase — the mirror of
 * Shared\MeetingRegisterService on purchase_* tables.
 *
 * Purchase could already read decisions, issues and actions INSIDE the one
 * meeting that produced them, and nowhere else. That answers "what came out of
 * this meeting?" and never "what is still open across every meeting?", which is
 * the only question a register exists for. Without it a Purchase action item
 * could be raised, missed and never surfaced again — the meeting it belonged to
 * was the only place it appeared.
 *
 * Read-only. Output keys and the status vocabularies are IDENTICAL to the shared
 * service's, because the same UI renders both — the two modules' status enums
 * were verified equal (Open/In_Progress/Resolved/Closed/Reopened/Cancelled for
 * issues; Open/In_Progress/Pending_Verification/Closed/Reopened/Cancelled for
 * actions), so a filter chip built for one works unchanged on the other.
 *
 * Three context fields the shared register carries are NULL here rather than
 * faked, because Purchase genuinely has no such column: `project`/`project_id`
 * (a Purchase meeting is scoped to a vendor, not a project), `work_package`, and
 * `agenda_item` (Purchase links decisions and actions to a participant, not to
 * the agenda row that produced them). Emitting the keys keeps the shared UI
 * rendering unchanged; filling them with something invented would be worse than
 * leaving the column blank.
 */
class PurchaseMeetingRegisterService
{
    /** How many rows a register will return before it stops. Mirrors shared. */
    private const MAX_ROWS = 500;

    /**
     * Decisions across every Purchase meeting in the tenant.
     *
     * @param  array{status?:string, vendor?:string, meeting_id?:int, search?:string, from?:string, to?:string}  $filters
     */
    public function decisions(int $tenantId, array $filters = []): array
    {
        $q = PurchaseMomDecision::where('tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['meeting_id'])) {
            $q->where('purchase_kickoff_meeting_id', (int) $filters['meeting_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($x) => $x->where('decision', 'like', $term)
                ->orWhere('decision_ref', 'like', $term)
                ->orWhere('impact', 'like', $term));
        }
        $this->applyDateWindow($q, $filters, 'effective_date');

        $rows = $q->orderByDesc('id')->limit(self::MAX_ROWS)->get();
        $meetings = $this->meetingContext($tenantId, $rows->pluck('purchase_kickoff_meeting_id'));

        return $rows
            ->map(fn ($d) => $this->withContext([
                'id' => $d->id,
                'ref' => $d->decision_ref,
                'decision' => $d->decision,
                // Purchase records the decider as a free-text participant name
                // rather than a User foreign key, so there is no relation to
                // prefer over it the way the shared service does.
                'decided_by' => $d->decided_by_names,
                'impact' => $d->impact,
                'effective_date' => $d->effective_date,
                'status' => $d->status,
                'agenda_item' => null,
            ], $meetings[$d->purchase_kickoff_meeting_id] ?? null))
            ->filter(fn ($r) => $this->matchesContext($r, $filters))
            ->values()->all();
    }

    /**
     * Issues across every meeting, open ones first — an issue register exists to
     * show what is still outstanding, so closed rows must not bury open ones.
     */
    public function issues(int $tenantId, array $filters = []): array
    {
        $q = PurchaseMomIssue::where('tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $filters['status'] === 'open'
                ? $q->whereIn('status', IssueStatus::OPEN_STATES)
                : $q->where('status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $q->where('severity', $filters['severity']);
        }
        if (! empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }
        if (! empty($filters['meeting_id'])) {
            $q->where('purchase_kickoff_meeting_id', (int) $filters['meeting_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($x) => $x->where('title', 'like', $term)
                ->orWhere('issue_ref', 'like', $term)
                ->orWhere('description', 'like', $term));
        }
        $this->applyDateWindow($q, $filters, 'due_date');

        $rows = $q->orderByDesc('id')->limit(self::MAX_ROWS)->get();
        $meetings = $this->meetingContext($tenantId, $rows->pluck('purchase_kickoff_meeting_id'));

        return $rows
            ->map(fn ($i) => $this->withContext([
                'id' => $i->id,
                'ref' => $i->issue_ref,
                'title' => $i->title,
                'description' => $i->description,
                'category' => $i->category,
                'severity' => $i->severity,
                'owner' => $i->owner_names,
                'due_date' => $i->due_date,
                'status' => $i->status,
                'status_label' => $i->status_label,
                'is_open' => $i->is_open,
                'is_overdue' => $i->is_overdue,
                'converted_to' => $i->converted_to,
                'converted_ref' => $i->converted_ref,
            ], $meetings[$i->purchase_kickoff_meeting_id] ?? null))
            ->filter(fn ($r) => $this->matchesContext($r, $filters))
            ->sortByDesc(fn ($r) => [$r['is_open'] ? 1 : 0, $r['is_overdue'] ? 1 : 0])
            ->values()->all();
    }

    /**
     * Action items across every meeting — the "Open Action Items" backlog.
     *
     * Defaults to OPEN, which is what the nav entry promises; status=all gives
     * the full history. A register that defaulted to everything would show a
     * closed action from a year ago above this week's overdue one.
     */
    public function actions(int $tenantId, array $filters = []): array
    {
        $q = PurchaseMomActionItem::where('tenant_id', $tenantId);

        $status = $filters['status'] ?? 'open';
        if ($status === 'open') {
            $q->whereIn('status', ActionStatus::OPEN_STATES);
        } elseif ($status === 'overdue') {
            $q->whereIn('status', ActionStatus::OPEN_STATES)
                ->whereNotNull('target_date')->whereDate('target_date', '<', now());
        } elseif ($status !== 'all' && $status !== '') {
            $q->where('status', $status);
        }

        if (! empty($filters['priority'])) {
            $q->where('priority', $filters['priority']);
        }
        if (! empty($filters['meeting_id'])) {
            $q->where('purchase_kickoff_meeting_id', (int) $filters['meeting_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($x) => $x->where('description', 'like', $term)
                ->orWhere('action_ref', 'like', $term)
                ->orWhere('responsible_names', 'like', $term));
        }
        $this->applyDateWindow($q, $filters, 'target_date');

        // Soonest due first, undated last — the backlog is read to find what is
        // about to slip, so a null date must not sort to the top.
        $rows = $q->orderByRaw('target_date is null, target_date asc')->limit(self::MAX_ROWS)->get();
        $meetings = $this->meetingContext($tenantId, $rows->pluck('purchase_kickoff_meeting_id'));

        return $rows
            ->map(fn ($a) => $this->withContext([
                'id' => $a->id,
                'ref' => $a->action_ref,
                'description' => trim(strip_tags((string) $a->description)),
                'responsible' => $a->responsible_names,
                'responsible_org' => $a->responsible_org,
                'priority' => $a->priority,
                'target_date' => $a->target_date,
                'status' => $a->status,
                'status_label' => $a->status_label,
                'is_open' => $a->is_open,
                'is_overdue' => $a->is_overdue,
                'agenda_item' => null,
                // Set once the action has been pushed into the Task module —
                // the register shows the link so an item already being chased
                // as a task is not chased twice.
                'task_id' => $a->task_id,
                'has_evidence' => $a->has_evidence,
            ], $meetings[$a->purchase_kickoff_meeting_id] ?? null))
            ->filter(fn ($r) => $this->matchesContext($r, $filters))
            ->values()->all();
    }

    /**
     * The filter options each register offers, built from what the module
     * actually defines rather than a hard-coded list that drifts from the data.
     */
    public function options(int $tenantId): array
    {
        return [
            'decision_statuses' => \App\Support\Purchase\PurchaseMomDecisionStatus::ALL,
            'issue_statuses' => IssueStatus::ALL,
            'issue_severities' => config('meetings.issue_severities', []),
            'issue_categories' => config('meetings.issue_categories', []),
            'action_statuses' => ActionStatus::ALL,
            'priorities' => config('meetings.meeting_priorities', []),
            'convert_targets' => config('meetings.issue_convert_targets', []),
            // The vendors that actually have meetings — the register's vendor
            // filter, built from the data so it never offers an empty option.
            'vendors' => PurchaseKickoffMeeting::where('tenant_id', $tenantId)
                ->whereNotNull('purchase_vendor_id')
                ->with('vendor:id,company_name')
                ->get()->pluck('vendor.company_name')->filter()->unique()->sort()->values()->all(),
        ];
    }

    /* ── internals ─────────────────────────────────────────────── */

    /** meeting_id → context for the rows in hand, in one query rather than N. */
    private function meetingContext(int $tenantId, $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return PurchaseKickoffMeeting::where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->with('vendor:id,company_name')
            ->get()
            ->mapWithKeys(fn ($m) => [$m->id => [
                'meeting_id' => $m->id,
                'meeting_no' => $m->meeting_no ?: $m->reference,
                'meeting_title' => $m->title,
                'meeting_type' => $m->meeting_type_label,
                'meeting_date' => $m->scheduled_at,
                // Purchase meetings are vendor-scoped and carry no project.
                'project_id' => null,
                'project' => null,
                'vendor' => $m->vendor?->company_name,
                'client_name' => $m->client_name,
                'work_package' => null,
            ]])->all();
    }

    private function withContext(array $row, ?array $context): array
    {
        return $row + ($context ?: [
            'meeting_id' => null, 'meeting_no' => null, 'meeting_title' => null,
            'meeting_type' => null, 'meeting_date' => null, 'project_id' => null,
            'project' => null, 'vendor' => null, 'client_name' => null, 'work_package' => null,
        ]);
    }

    /**
     * Vendor filtering happens after the context join rather than in SQL: the
     * meeting owns the vendor, and the register row does not carry it.
     */
    private function matchesContext(array $row, array $filters): bool
    {
        if (! empty($filters['vendor']) && $row['vendor'] !== $filters['vendor']) {
            return false;
        }

        return true;
    }

    private function applyDateWindow($q, array $filters, string $column): void
    {
        if (! empty($filters['from'])) {
            $q->whereDate($column, '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate($column, '<=', $filters['to']);
        }
    }
}
