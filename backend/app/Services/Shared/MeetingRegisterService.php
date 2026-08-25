<?php

namespace App\Services\Shared;

use App\Contracts\ProjectDirectoryContract;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMomItem;
use App\Models\Shared\MeetingDecision;
use App\Models\Shared\MeetingIssue;
use App\Support\Shared\KickoffSubject;
use App\Support\Shared\MeetingIssueStatus;
use App\Support\Shared\MomActionStatus;

/**
 * The three cross-meeting registers (Meeting.docx §8, §9, §10).
 *
 * Decisions, issues and actions were only ever readable inside the one meeting
 * that produced them. §9 asks for the opposite — "this creates a searchable
 * Project Decision Register" — and the same is true of the action backlog the
 * nav calls "Open Action Items": the useful view is across meetings, filtered by
 * project or vendor, not one meeting at a time.
 *
 * Read-only. Every row carries its meeting, project and vendor so a register row
 * can be clicked straight through to where it came from.
 */
class MeetingRegisterService
{
    /**
     * Decisions across every meeting in the tenant (Meeting.docx §9).
     *
     * @param  array{status?:string, project_id?:int, vendor?:string, meeting_id?:int, search?:string, from?:string, to?:string}  $filters
     */
    public function decisions(int $tenantId, array $filters = []): array
    {
        $q = MeetingDecision::where('tenant_id', $tenantId)
            ->with(['decidedBy:id,name', 'agendaItem:id,item']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['meeting_id'])) {
            $q->where('kickoff_meeting_id', (int) $filters['meeting_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($x) => $x->where('decision', 'like', $term)
                ->orWhere('decision_ref', 'like', $term)
                ->orWhere('impact', 'like', $term));
        }
        $this->applyDateWindow($q, $filters, 'effective_date');

        $rows = $q->orderByDesc('id')->limit(500)->get();
        $meetings = $this->meetingContext($tenantId, $rows->pluck('kickoff_meeting_id'));

        return $rows
            ->map(fn ($d) => $this->withContext([
                'id' => $d->id,
                'ref' => $d->decision_ref,
                'decision' => $d->decision,
                'decided_by' => $d->decidedBy?->name ?: $d->decided_by_names,
                'impact' => $d->impact,
                'effective_date' => $d->effective_date,
                'status' => $d->status,
                'agenda_item' => $d->agendaItem?->item,
            ], $meetings[$d->kickoff_meeting_id] ?? null))
            ->filter(fn ($r) => $this->matchesContext($r, $filters))
            ->values()->all();
    }

    /**
     * Issues across every meeting (Meeting.docx §10), open ones first — an issue
     * register exists to show what is still outstanding.
     */
    public function issues(int $tenantId, array $filters = []): array
    {
        $q = MeetingIssue::where('tenant_id', $tenantId)->with(['owner:id,name']);

        if (! empty($filters['status'])) {
            $filters['status'] === 'open'
                ? $q->whereIn('status', MeetingIssueStatus::OPEN_STATES)
                : $q->where('status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $q->where('severity', $filters['severity']);
        }
        if (! empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }
        if (! empty($filters['meeting_id'])) {
            $q->where('kickoff_meeting_id', (int) $filters['meeting_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($x) => $x->where('title', 'like', $term)
                ->orWhere('issue_ref', 'like', $term)
                ->orWhere('description', 'like', $term));
        }
        $this->applyDateWindow($q, $filters, 'due_date');

        $rows = $q->orderByDesc('id')->limit(500)->get();
        $meetings = $this->meetingContext($tenantId, $rows->pluck('kickoff_meeting_id'));

        return $rows
            ->map(fn ($i) => $this->withContext([
                'id' => $i->id,
                'ref' => $i->issue_ref,
                'title' => $i->title,
                'description' => $i->description,
                'category' => $i->category,
                'severity' => $i->severity,
                'owner' => $i->owner?->name ?: $i->owner_names,
                'due_date' => $i->due_date,
                'status' => $i->status,
                'status_label' => $i->status_label,
                'is_open' => $i->is_open,
                'is_overdue' => $i->is_overdue,
                'converted_to' => $i->converted_to,
                'converted_ref' => $i->converted_ref,
            ], $meetings[$i->kickoff_meeting_id] ?? null))
            ->filter(fn ($r) => $this->matchesContext($r, $filters))
            ->sortByDesc(fn ($r) => [$r['is_open'] ? 1 : 0, $r['is_overdue'] ? 1 : 0])
            ->values()->all();
    }

    /**
     * Action items across every meeting — the nav's "Open Action Items"
     * (Meeting.docx §8). Defaults to the open backlog, which is what the nav
     * entry promises; pass status=all for the full history.
     */
    public function actions(int $tenantId, array $filters = []): array
    {
        $q = KickoffMomItem::where('tenant_id', $tenantId)
            ->with(['responsible:id,name', 'agendaItem:id,item', 'task:id,name,status']);

        $status = $filters['status'] ?? 'open';
        if ($status === 'open') {
            $q->whereIn('status', MomActionStatus::OPEN_STATES);
        } elseif ($status === 'overdue') {
            $q->whereIn('status', MomActionStatus::OPEN_STATES)
                ->whereNotNull('target_date')->whereDate('target_date', '<', now());
        } elseif ($status !== 'all' && $status !== '') {
            $q->where('status', $status);
        }

        if (! empty($filters['priority'])) {
            $q->where('priority', $filters['priority']);
        }
        if (! empty($filters['meeting_id'])) {
            $q->where('kickoff_meeting_id', (int) $filters['meeting_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($x) => $x->where('description', 'like', $term)
                ->orWhere('action_ref', 'like', $term)
                ->orWhere('responsible_names', 'like', $term));
        }
        $this->applyDateWindow($q, $filters, 'target_date');

        $rows = $q->orderByRaw('target_date is null, target_date asc')->limit(500)->get();
        $meetings = $this->meetingContext($tenantId, $rows->pluck('kickoff_meeting_id'));

        return $rows
            ->map(fn ($a) => $this->withContext([
                'id' => $a->id,
                'ref' => $a->action_ref,
                'description' => trim(strip_tags((string) $a->description)),
                'responsible' => $a->responsible?->name ?: $a->responsible_names,
                'responsible_org' => $a->responsible_org,
                'priority' => $a->priority,
                'target_date' => $a->target_date,
                'status' => $a->status,
                'status_label' => $a->status_label,
                'is_open' => $a->is_open,
                'is_overdue' => $a->is_overdue,
                'agenda_item' => $a->agendaItem?->item,
                'task_id' => $a->task_id,
                'has_evidence' => (bool) $a->evidence_path,
            ], $meetings[$a->kickoff_meeting_id] ?? null))
            ->filter(fn ($r) => $this->matchesContext($r, $filters))
            ->values()->all();
    }

    /**
     * The filter options each register offers, built from what is actually
     * present rather than a hard-coded list that drifts from the data.
     */
    public function options(int $tenantId): array
    {
        return [
            'decision_statuses' => config('meetings.decision_statuses', []),
            'issue_statuses' => MeetingIssueStatus::ALL,
            'issue_severities' => config('meetings.issue_severities', []),
            'issue_categories' => config('meetings.issue_categories', []),
            'action_statuses' => MomActionStatus::ALL,
            'priorities' => config('meetings.meeting_priorities', []),
            'convert_targets' => config('meetings.issue_convert_targets', []),
        ];
    }

    /* ── internals ─────────────────────────────────────────────── */

    /** meeting_id → { meeting_no, title, date, project, vendor } for the rows in hand. */
    private function meetingContext(int $tenantId, $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $projects = app(ProjectDirectoryContract::class);

        return KickoffMeeting::where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->with('kickoffable')
            ->get()
            ->mapWithKeys(fn ($m) => [$m->id => [
                'meeting_id' => $m->id,
                'meeting_no' => $m->meeting_no,
                'meeting_title' => $m->title,
                'meeting_type' => $m->meeting_type_label,
                'meeting_date' => $m->scheduled_at,
                'project_id' => $m->project_id,
                'project' => $m->project_id
                    ? ($projects->labelFor((int) $m->project_id, $tenantId) ?? 'Project #'.$m->project_id)
                    : null,
                'vendor' => KickoffSubject::nameOf($m->kickoffable),
                'client_name' => $m->client_name,
                // §9 and §10 both list the related work package on the register
                // row. It lives on the meeting, so it rides along with the rest
                // of the context rather than being duplicated per item.
                'work_package' => $m->work_package,
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
     * Project / vendor filtering happens after the context join rather than in
     * SQL: the meeting owns both, and the register row does not carry them.
     */
    private function matchesContext(array $row, array $filters): bool
    {
        if (! empty($filters['project_id']) && (string) $row['project_id'] !== (string) $filters['project_id']) {
            return false;
        }
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
