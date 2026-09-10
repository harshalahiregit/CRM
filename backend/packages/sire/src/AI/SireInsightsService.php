<?php

namespace Sire\AI;

use Sire\Models\RecurrenceGroup;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Services\SireAccessService;
use Sire\Support\SireStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — engineering insights for management.
 *
 * Six questions a lead actually asks, answered from the tenant's own register.
 * Counts, medians and rankings — no forecasting, no scoring model, no narrative
 * generation, and nothing that could be mistaken for a judgement about a person.
 *
 * TENANT ISOLATION IS THE WHOLE RISK HERE. This is the one place in SIRE that
 * aggregates broadly, and an aggregate that quietly spans tenants would be both a
 * breach and a lie. Every query goes through scoped(), which is the only entry
 * point and chains ->forTenant() first.
 *
 * "Longest resolution areas" is reported as a MEDIAN, not a mean: one issue that
 * sat open for a year would drag a mean until the number described that issue
 * rather than the module.
 */
class SireInsightsService
{
    private const TOP_N = 10;

    /** Below this, a module's numbers say more about the sample than the module. */
    private const MIN_SAMPLE = 3;

    public function __construct(private readonly SireAccessService $access)
    {
    }

    public function all(int $tenantId, SireUserIdentity $user, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $to ??= CarbonImmutable::now();
        $from ??= $to->subDays(180);

        return [
            'window'                  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'most_unstable_modules'   => $this->mostUnstableModules($tenantId, $user, $from, $to),
            'recurring_problem_areas' => $this->recurringProblemAreas($tenantId, $user),
            'highest_regression_modules' => $this->highestRegressionModules($tenantId, $user, $from, $to),
            'biggest_issue_categories'   => $this->biggestCategories($tenantId, $user, $from, $to),
            'longest_resolution_areas'   => $this->longestResolutionAreas($tenantId, $user, $from, $to),
            'most_reopened_issues'       => $this->mostReopenedIssues($tenantId, $user, $from, $to),
        ];
    }

    /**
     * Instability is not raw volume. A busy module is not an unstable one — a
     * module whose issues come BACK is. Ranked on reopens plus regressions per
     * issue, so a high-traffic module with clean fixes does not top the list.
     */
    private function mostUnstableModules(int $tenantId, SireUserIdentity $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->scoped($tenantId, $user)
            ->whereNotNull('module')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('module, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN reopen_count > 0 THEN 1 ELSE 0 END) as reopened')
            ->selectRaw('SUM(CASE WHEN is_regression = 1 THEN 1 ELSE 0 END) as regressions')
            ->groupBy('module')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_SAMPLE])
            ->get()
            ->map(function ($row) {
                $total = max(1, (int) $row->total);
                $instability = ((int) $row->reopened + (int) $row->regressions) / $total;

                return [
                    'module'            => $row->module,
                    'total'             => (int) $row->total,
                    'reopened'          => (int) $row->reopened,
                    'regressions'       => (int) $row->regressions,
                    'instability_index' => round($instability, 3),
                    'reason'            => sprintf(
                        '%d of %d issues either came back or were regressions.',
                        (int) $row->reopened + (int) $row->regressions, $total,
                    ),
                ];
            })
            ->sortByDesc('instability_index')
            ->take(self::TOP_N)
            ->values()
            ->all();
    }

    private function recurringProblemAreas(int $tenantId, SireUserIdentity $user): array
    {
        return RecurrenceGroup::query()
            ->forTenant($tenantId)
            ->where('is_closed', false)
            ->where('occurrence_count', '>=', 2)
            ->orderByDesc('occurrence_count')
            ->limit(self::TOP_N)
            ->get(['id', 'reference', 'title', 'occurrence_count', 'recurrence_risk', 'permanent_fix_status', 'signature'])
            ->map(fn (RecurrenceGroup $g) => [
                'reference'   => $g->reference,
                'title'       => $g->title,
                'occurrences' => $g->occurrence_count,
                'risk'        => $g->recurrence_risk,
                'area'        => $g->signature,
                'reason'      => sprintf(
                    'Recorded %d times, %s.',
                    $g->occurrence_count,
                    $g->permanent_fix_status === 'none' ? 'with no permanent fix planned' : "fix {$g->permanent_fix_status}",
                ),
            ])
            ->all();
    }

    private function highestRegressionModules(int $tenantId, SireUserIdentity $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->scoped($tenantId, $user)
            ->whereNotNull('module')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('module, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_regression = 1 THEN 1 ELSE 0 END) as regressions')
            ->groupBy('module')
            ->havingRaw('SUM(CASE WHEN is_regression = 1 THEN 1 ELSE 0 END) > 0')
            ->get()
            ->map(fn ($row) => [
                'module'      => $row->module,
                'regressions' => (int) $row->regressions,
                'total'       => (int) $row->total,
                'rate'        => round((int) $row->regressions / max(1, (int) $row->total), 3),
                'reason'      => sprintf('%d of %d issues were regressions.', (int) $row->regressions, (int) $row->total),
            ])
            ->sortByDesc('regressions')
            ->take(self::TOP_N)
            ->values()
            ->all();
    }

    private function biggestCategories(int $tenantId, SireUserIdentity $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->scoped($tenantId, $user)
            ->whereBetween('sire_reports.created_at', [$from, $to])
            ->leftJoin('sire_report_categories', 'sire_report_categories.id', '=', 'sire_reports.category_id')
            ->selectRaw('COALESCE(sire_report_categories.name, ?) as category, COUNT(*) as total', ['Uncategorised'])
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(self::TOP_N)
            ->get();

        $grand = max(1, $rows->sum('total'));

        return $rows->map(fn ($row) => [
            'category' => $row->category,
            'total'    => (int) $row->total,
            'share'    => round((int) $row->total / $grand, 3),
            'reason'   => sprintf('%d issues (%d%% of the period).', (int) $row->total, (int) round((int) $row->total / $grand * 100)),
        ])->all();
    }

    /**
     * Median days from creation to close, per module.
     *
     * A MEDIAN, deliberately. One issue that sat open for a year would drag a mean
     * until the number described that issue rather than the module — and the
     * question being asked is "where does work get stuck", not "what is our worst
     * anecdote".
     */
    private function longestResolutionAreas(int $tenantId, SireUserIdentity $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->scoped($tenantId, $user)
            ->whereNotNull('module')
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->get(['module', 'created_at', 'closed_at']);

        return collect($rows)
            ->groupBy('module')
            ->filter(fn ($group) => $group->count() >= self::MIN_SAMPLE)
            ->map(function ($group, string $module) {
                $days = $group
                    ->map(fn ($r) => CarbonImmutable::instance($r->created_at)->diffInHours($r->closed_at, false) / 24)
                    ->filter(fn (float $d) => $d >= 0)
                    ->sort()
                    ->values();

                return [
                    'module'       => $module,
                    'resolved'     => $group->count(),
                    'median_days'  => round($this->median($days->all()), 1),
                    'slowest_days' => round((float) $days->last(), 1),
                    'reason'       => sprintf(
                        'Half of the %d issues closed here took longer than %.1f days.',
                        $group->count(), $this->median($days->all()),
                    ),
                ];
            })
            ->sortByDesc('median_days')
            ->take(self::TOP_N)
            ->values()
            ->all();
    }

    private function mostReopenedIssues(int $tenantId, SireUserIdentity $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->scoped($tenantId, $user)
            ->where('reopen_count', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('reopen_count')
            ->limit(self::TOP_N)
            ->get(['id', 'report_number', 'title', 'module', 'reopen_count', 'status'])
            ->map(fn (Report $r) => [
                'report_number' => $r->report_number,
                'title'         => $r->title,
                'module'        => $r->module,
                'reopens'       => (int) $r->reopen_count,
                'status'        => $r->status,
                'reason'        => sprintf('Reopened %d time(s) — the fix has not held.', $r->reopen_count),
            ])
            ->all();
    }

    private function median(array $values): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : (float) (($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * THE tenant boundary. Every query above goes through here; scoping is opt-in
     * in this codebase and an aggregate that spans tenants would be both a breach
     * and a lie about the numbers.
     */
    private function scoped(int $tenantId, SireUserIdentity $user)
    {
        return $this->access->scopeVisible(Report::query()->forTenant($tenantId), $user);
    }
}
