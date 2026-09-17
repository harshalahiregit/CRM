<?php

namespace Sire\Services;

use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SirePriority;
use Sire\Services\SireSlaService;
use Sire\Support\SireStatus;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * SIRE — dashboard tiles and the filtered register behind them.
 *
 * Two rules hold this together:
 *
 *   TENANT FIRST. Every query in this class starts from base(), which chains
 *   ->forTenant() before anything else. There is no global scope in this codebase
 *   and no safety net; a scope helper that forgets it returns every tenant's rows
 *   silently. base() is the only entry point, and it is the reason there is not a
 *   single raw Report::query() below.
 *
 *   ALLOWLIST FILTERS. applyFilters() reads from a fixed key list. An unknown
 *   query parameter is ignored, never forwarded to the query builder.
 *
 * Counts only, no analytics. Trend lines, MTTR, escape rate and per-developer
 * throughput are deliberately absent — the brief says not yet, and each of them
 * needs a decision about what it measures before it needs code.
 */
class SireDashboardService
{
    /** Overdue looks this far back for "recently resolved". */
    private const RECENT_DAYS = 7;

    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireSlaService $sla,
    )
    {
    }

    /**
     * Every scope the dashboard understands. Tiles and the register share these,
     * so a tile's number and the list you get by clicking it cannot disagree.
     */
    public const SCOPES = [
        'open', 'critical', 'high_priority', 'overdue', 'sla_breached',
        'mine', 'awaiting_qa', 'qa_failed', 'reopened', 'recently_resolved', 'all',
    ];

    public function tiles(int $tenantId, SireUserIdentity $user, array $filters = []): array
    {
        $out = [];

        foreach (['open', 'critical', 'high_priority', 'overdue', 'sla_breached',
                  'mine', 'awaiting_qa', 'qa_failed', 'reopened', 'recently_resolved'] as $scope) {
            $out[$scope] = $this->scoped($tenantId, $user, $scope, $filters)->count();
        }

        return $out;
    }

    public function register(int $tenantId, SireUserIdentity $user, ?string $scope, array $filters, int $perPage = 25)
    {
        $rows = $this->scoped($tenantId, $user, $scope ?: 'all', $filters)
            ->with([
                // The SLA targets live on the severity row, so a narrow select
                // here made every computed clock fall through to "no SLA".
                'severity:id,name,code,level,color,ack_target_minutes,triage_target_minutes,resolve_target_minutes',
                'category:id,name,code',
                'assignee:id,name',
                'qaAssignee:id,name',
                'reporter:id,name',
            ])
            ->orderByRaw(self::PRIORITY_ORDER)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // SLA is computed, never stored -- so a list row carries none unless it is
        // computed here. Without this the register's SLA column rendered an em
        // dash on every row forever, which reads as "no SLA" rather than "not
        // calculated". Done over the page's rows only, so the cost is bounded by
        // per_page and not by the size of the register.
        $rows->getCollection()->each(function (Report $report): void {
            $report->setAttribute('sla', $this->sla->for($report));
        });

        return $rows;
    }

    /**
     * Portable priority ordering. MySQL's FIELD() would be shorter, but the whole
     * test suite runs on SQLite, which has no FIELD() — that gap has already
     * broken a deploy in this codebase.
     */
    private const PRIORITY_ORDER =
        "CASE priority WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 WHEN 'p3' THEN 3 WHEN 'p4' THEN 4 ELSE 5 END";

    /**
     * THE tenant boundary. Nothing in this class queries Report without going
     * through here.
     */
    private function base(int $tenantId, SireUserIdentity $user): EloquentBuilder
    {
        return $this->access->scopeVisible(
            Report::query()->forTenant($tenantId),
            $user,
        );
    }

    /**
     * The register's own query, for callers that need the rows rather than a page.
     *
     * Public so the export can reuse EVERY filter and scope the register applies
     * -- including scopeVisible, which is what keeps a developer's export to the
     * issues they were already allowed to read. An export that built its own
     * query would be a second place for the visibility rules to live, and the
     * one that gets forgotten is always the one that leaks.
     */
    public function exportQuery(int $tenantId, SireUserIdentity $user, string $scope, array $filters): EloquentBuilder
    {
        return $this->scoped($tenantId, $user, $scope ?: 'open', $filters);
    }

    private function scoped(int $tenantId, SireUserIdentity $user, string $scope, array $filters): EloquentBuilder
    {
        $query = $this->applyFilters($this->base($tenantId, $user), $filters, $tenantId);

        return $this->applyScope($query, $scope, $user);
    }

    private function applyScope(EloquentBuilder $query, string $scope, SireUserIdentity $user): EloquentBuilder
    {
        $open = fn (EloquentBuilder $q) => $q->whereNotIn('status', SireStatus::TERMINAL);

        return match ($scope) {
            'open'          => $open($query),
            'critical'      => $open($query)->whereHas('severity', fn ($q) => $q->where('code', 'critical')),
            'high_priority' => $open($query)->whereIn('priority', [SirePriority::P1, SirePriority::P2]),

            // Overdue and SLA-breached are NOT the same question, and the dashboard
            // shows both on purpose:
            //   overdue      — STILL OPEN and past its resolution deadline. Work to do.
            //   sla_breached — either clock breached, INCLUDING issues already closed
            //                  late. A reporting number, not a work queue.
            //
            // Both read the notified-state columns rather than a stored deadline.
            // SLA state is computed from settings that a tenant can change at any
            // time, so a denormalised sla_due_at column would be wrong the moment a
            // policy was edited. These columns record the highest state each clock
            // has actually REACHED — written by the sweep whether or not a
            // notification went out, so muting notifications does not blind the
            // dashboard.
            //
            // Cost of this choice: up to one sweep interval (15 minutes) of lag
            // before a newly breached issue appears. Acceptable for a dashboard;
            // it would not be for an alert, which is why alerting is the sweep's
            // job and not this query's.
            'overdue' => $open($query)->where('sla_resolve_notified_state', 'breached'),
            'sla_breached' => $query->where(function ($q) {
                $q->where('sla_ack_notified_state', 'breached')
                    ->orWhere('sla_resolve_notified_state', 'breached');
            }),

            'mine' => $open($query)->where(function ($q) use ($user) {
                $q->where('assignee_id', $user->id)
                    ->orWhere('qa_assignee_id', $user->id)
                    ->orWhere('reporter_id', $user->id);
            }),

            'awaiting_qa' => $query->where('status', SireStatus::READY_FOR_QA),
            'qa_failed'   => $query->where('status', SireStatus::QA_FAILED),

            'reopened' => $open($query)->where(function ($q) {
                $q->where('status', SireStatus::REOPENED)->orWhere('reopen_count', '>', 0);
            }),

            'recently_resolved' => $query
                ->whereIn('status', SireStatus::TERMINAL)
                ->where('closed_at', '>=', now()->subDays(self::RECENT_DAYS)),

            default => $query,
        };
    }

    /**
     * The allowlist. A key that is not named here cannot reach the query builder,
     * whatever the client sends.
     */
    private function applyFilters(EloquentBuilder $query, array $filters, int $tenantId): EloquentBuilder
    {
        // tenant_id is accepted and VALIDATED rather than applied. A user belongs
        // to exactly one tenant and there is no cross-tenant read path, so the only
        // legal value is their own. Rejecting a mismatch instead of ignoring it
        // means that if cross-tenant access is ever built, this fails closed.
        if (! empty($filters['tenant_id']) && (int) $filters['tenant_id'] !== $tenantId) {
            abort(404);
        }

        return $query
            // whereIn, so one module and a list of them take the same path. The
            // register passes a string, the export passes an array.
            ->when(
                $filters['module'] ?? null,
                fn ($q, $v) => $q->whereIn('module', array_values(array_filter((array) $v)))
            )
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->whereHas('category', fn ($c) => $c->where('code', $v)))
            ->when($filters['severity_id'] ?? null, fn ($q, $v) => $q->where('severity_id', (int) $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->whereIn('priority', $this->csv($v, SirePriority::ALL)))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereIn('status', $this->csv($v, SireStatus::all())))
            ->when($filters['assignee_id'] ?? null, fn ($q, $v) => $q->where('assignee_id', (int) $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v));
    }

    /**
     * Split a comma list and keep only values from the allowed set. Returning a
     * sentinel rather than an empty array matters: whereIn([]) matches NOTHING in
     * SQL, so a filter of entirely invalid values would return an empty page while
     * looking like it worked. An impossible sentinel makes that explicit.
     */
    private function csv(string $value, array $allowed): array
    {
        $values = array_values(array_intersect(
            array_map('trim', explode(',', $value)),
            $allowed,
        ));

        return $values === [] ? ['__none__'] : $values;
    }
}
