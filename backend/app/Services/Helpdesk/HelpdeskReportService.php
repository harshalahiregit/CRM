<?php

namespace App\Services\Helpdesk;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Help Desk report: volume, outcome and how long people waited.
 *
 * Distinct from the analytics dashboard, which answers "what needs attention
 * right now". A report is pointed at a range and taken to a meeting, so it is
 * filterable, exportable, and every figure comes from one scoped set of tickets
 * rather than from separate queries that can quietly disagree.
 *
 * The two timings are the ones that actually describe service:
 *
 *  - first response — how long somebody waited before a human replied at all;
 *  - resolution — how long the whole thing took.
 *
 * Both are measured only on tickets that reached that milestone. Averaging an
 * unanswered ticket in as a zero would report the worst cases as the best ones,
 * so unanswered tickets are counted separately instead — that count is the
 * point, and burying it inside an average hides it.
 */
class HelpdeskReportService
{
    /** @param array{from?:string,to?:string,status?:string,priority?:string,assigned_to?:int|string|null,department_id?:int|string|null} $filters */
    public function build(int $tenantId, array $filters = []): array
    {
        $tickets = $this->tickets($tenantId, $filters);

        return [
            'filters' => [
                'from'          => $filters['from'] ?? null,
                'to'            => $filters['to'] ?? null,
                'status'        => $filters['status'] ?? null,
                'priority'      => $filters['priority'] ?? null,
                'assigned_to'   => $filters['assigned_to'] ?? null,
                'department_id' => $filters['department_id'] ?? null,
            ],
            'generated_at'  => now()->toDateTimeString(),
            'totals'        => $this->totals($tickets),
            'by_status'     => $this->countBy($tickets, 'status', 'status'),
            'by_priority'   => $this->countBy($tickets, 'priority', 'priority'),
            'by_agent'      => $this->byAgent($tickets),
            'by_department' => $this->countBy($tickets, 'department', 'department'),
            'by_month'      => $this->byMonth($tickets),
        ];
    }

    /* ── Scope ──────────────────────────────────────────────────────────── */

    private function tickets(int $tenantId, array $filters): Collection
    {
        return DB::table('tickets as t')
            ->leftJoin('ticket_departments as d', 'd.id', '=', 't.department_id')
            ->where('t.tenant_id', $tenantId)
            ->whereNull('t.deleted_at')
            // Merged tickets are not their own piece of work — counting them
            // would double every merged conversation.
            ->whereNull('t.merged_into_id')
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('t.created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('t.created_at', '<=', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('t.status', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('t.priority', $v))
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('t.assigned_to', (int) $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('t.department_id', (int) $v))
            ->selectRaw('t.id, t.subject, t.status, t.priority, t.assigned_to, t.created_at,
                         t.first_responded_at, t.resolved_at, t.due_date, t.reopened_count,
                         d.name as department')
            ->limit(20000)
            ->get()
            ->map(function ($r) {
                $created = $r->created_at ? Carbon::parse($r->created_at) : null;
                $first   = $r->first_responded_at ? Carbon::parse($r->first_responded_at) : null;
                $done    = $r->resolved_at ? Carbon::parse($r->resolved_at) : null;
                $due     = $r->due_date ? Carbon::parse($r->due_date) : null;

                return [
                    'id'          => $r->id,
                    'subject'     => $r->subject,
                    'status'      => $r->status ?: 'Open',
                    'priority'    => $r->priority ?: 'Normal',
                    'agent_id'    => $r->assigned_to,
                    'department'  => $r->department ?: 'Unassigned',
                    'created_at'  => $created,
                    'resolved'    => (bool) $done,
                    'answered'    => (bool) $first,
                    'reopened'    => (int) $r->reopened_count,
                    // Minutes, and null when the milestone was never reached —
                    // so the averages below can exclude them honestly.
                    'response_mins'   => ($created && $first) ? $created->diffInMinutes($first) : null,
                    'resolution_mins' => ($created && $done) ? $created->diffInMinutes($done) : null,
                    // Past its promised date and still not resolved, or resolved
                    // after it. Either way the promise was missed.
                    'breached' => $due && (($done && $done->gt($due)) || (! $done && $due->isPast())),
                ];
            });
    }

    /* ── Aggregates ─────────────────────────────────────────────────────── */

    private function totals(Collection $t): array
    {
        $answered = $t->whereNotNull('response_mins');
        $resolved = $t->whereNotNull('resolution_mins');

        return [
            'tickets'          => $t->count(),
            'resolved'         => $t->where('resolved', true)->count(),
            'open'             => $t->where('resolved', false)->count(),
            'reopened'         => $t->where('reopened', '>', 0)->count(),
            'never_answered'   => $t->where('answered', false)->count(),
            'sla_breached'     => $t->where('breached', true)->count(),
            'resolution_rate'  => $t->count() ? round($t->where('resolved', true)->count() / $t->count() * 100, 1) : null,
            // Averages over the tickets that actually reached the milestone.
            'avg_response_mins'   => $answered->count() ? (int) round($answered->avg('response_mins')) : null,
            'avg_resolution_mins' => $resolved->count() ? (int) round($resolved->avg('resolution_mins')) : null,
            'answered_count'      => $answered->count(),
            'resolved_count'      => $resolved->count(),
        ];
    }

    /** One shape for the plain "count and outcome by X" tables. */
    private function countBy(Collection $t, string $key, string $label): array
    {
        return $t->groupBy($key)->map(function (Collection $g, $value) use ($label) {
            $resolved = $g->whereNotNull('resolution_mins');

            return [
                $label                => (string) $value,
                'tickets'             => $g->count(),
                'resolved'            => $g->where('resolved', true)->count(),
                'open'                => $g->where('resolved', false)->count(),
                'sla_breached'        => $g->where('breached', true)->count(),
                'avg_resolution_mins' => $resolved->count() ? (int) round($resolved->avg('resolution_mins')) : null,
            ];
        })->sortByDesc('tickets')->values()->all();
    }

    private function byAgent(Collection $t): array
    {
        $names = User::whereIn('id', $t->pluck('agent_id')->filter()->unique())->pluck('name', 'id');

        return $t->groupBy('agent_id')->map(function (Collection $g, $agentId) use ($names) {
            $answered = $g->whereNotNull('response_mins');
            $resolved = $g->whereNotNull('resolution_mins');

            return [
                'agent_id'            => $agentId ?: null,
                'agent'               => $agentId ? ($names[$agentId] ?? 'User #'.$agentId) : 'Unassigned',
                'tickets'             => $g->count(),
                'resolved'            => $g->where('resolved', true)->count(),
                'open'                => $g->where('resolved', false)->count(),
                'sla_breached'        => $g->where('breached', true)->count(),
                'avg_response_mins'   => $answered->count() ? (int) round($answered->avg('response_mins')) : null,
                'avg_resolution_mins' => $resolved->count() ? (int) round($resolved->avg('resolution_mins')) : null,
            ];
        })->sortByDesc('tickets')->values()->all();
    }

    private function byMonth(Collection $t): array
    {
        return $t->filter(fn ($r) => $r['created_at'])
            ->groupBy(fn ($r) => $r['created_at']->format('Y-m'))
            ->map(fn (Collection $g, $month) => [
                'month'    => $month,
                'label'    => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'tickets'  => $g->count(),
                'resolved' => $g->where('resolved', true)->count(),
                'breached' => $g->where('breached', true)->count(),
            ])->sortBy('month')->values()->all();
    }
}
