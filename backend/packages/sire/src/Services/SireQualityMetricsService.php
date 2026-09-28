<?php

namespace Sire\Services;

use Sire\Models\RecurrenceGroup;
use Sire\Models\Release;
use Sire\Models\Report;
use Sire\Models\WorkCycle;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * SIRE — quality metrics.
 *
 * Rates are where dashboards lie, so every one here is computed the same way and
 * carries its own denominator:
 *
 *   A ZERO DENOMINATOR IS "NO DATA", NOT ZERO PERCENT. "0%" reads as perfect;
 *   "—" reads as "we have not measured that yet". Only one of those is true when
 *   nothing has been resolved in the window, and shipping the wrong one turns a
 *   quality dashboard into a reassurance machine.
 *
 *   A SMALL SAMPLE IS FLAGGED. Three issues and one regression is not a 33%
 *   regression rate in any useful sense.
 *
 * Direct port of tests/reference/quality.mjs; both sides run
 * fixtures/quality-metrics-cases.json.
 *
 * No trend modelling, no forecasting, no anomaly detection, no AI — counts,
 * ratios and buckets over data the register already holds.
 */
class SireQualityMetricsService
{
    /** Below this, a percentage says more about the sample than the team. */
    public const LOW_CONFIDENCE_SAMPLE = 5;

    public function __construct(private readonly SireAccessService $access)
    {
    }

    /**
     * @return array{value: ?float, display: string, sample: int, suspect: bool, low_confidence: bool}
     */
    public function rate(int $numerator, int $denominator): array
    {
        if ($denominator <= 0) {
            return [
                'value' => null, 'display' => '—', 'sample' => max(0, $denominator),
                'suspect' => false, 'low_confidence' => false,
            ];
        }

        // A numerator above its denominator means the two were counted over
        // different sets. Clamp so the UI stays sane, but flag rather than hide.
        $suspect = $numerator > $denominator;
        $value = $suspect ? 1.0 : $numerator / $denominator;

        // Cast both sides. floor() always returns a float, while $percent is an
        // int whenever the division came out exact -- so a strict comparison was
        // false for every whole percentage and 100% rendered as "100.0%".
        $percent = (float) $value * 100;
        $display = (floor($percent) === $percent)
            ? number_format($percent, 0).'%'
            : number_format($percent, 1).'%';

        return [
            'value'          => round($value, 4),
            'display'        => $display,
            'sample'         => $denominator,
            'suspect'        => $suspect,
            'low_confidence' => $denominator < self::LOW_CONFIDENCE_SAMPLE,
        ];
    }

    /**
     * The four headline rates for a window.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $window
     */
    public function rates(int $tenantId, SireUserIdentity $user, array $window, array $filters = []): array
    {
        $created = $this->scoped($tenantId, $user, $filters)
            ->whereBetween('created_at', [$window['from'], $window['to']])->count();

        $resolved = $this->scoped($tenantId, $user, $filters)
            ->whereIn('status', [SireStatus::CLOSED, SireStatus::PRODUCTION_VALIDATED])
            ->whereBetween('closed_at', [$window['from'], $window['to']])->count();

        // Reopens are counted where they HAPPENED, not where the issue was
        // created — an issue filed in January and reopened in March belongs to
        // March's number.
        $reopened = $this->scoped($tenantId, $user, $filters)
            ->where('reopen_count', '>', 0)
            ->whereBetween('reopened_at', [$window['from'], $window['to']])->count();

        $regressions = $this->scoped($tenantId, $user, $filters)
            ->where('is_regression', true)
            ->whereBetween('created_at', [$window['from'], $window['to']])->count();

        $recurring = $this->scoped($tenantId, $user, $filters)
            ->whereNotNull('recurrence_group_id')
            ->whereBetween('created_at', [$window['from'], $window['to']])->count();

        // QA rejection is a property of RUNS, not of issues: an issue that failed
        // QA three times is three rejections out of however many runs happened.
        // sire_work_cycles already records exactly that.
        $qaCycles = WorkCycle::query()->forTenant($tenantId)
            ->where('phase', WorkCycle::PHASE_QA)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$window['from'], $window['to']])
            ->whereIn('outcome', ['passed', 'failed'])
            ->count();

        $qaFailed = WorkCycle::query()->forTenant($tenantId)
            ->where('phase', WorkCycle::PHASE_QA)
            ->where('outcome', 'failed')
            ->whereBetween('ended_at', [$window['from'], $window['to']])
            ->count();

        return [
            'reopen_rate'       => $this->rate($reopened, $resolved),
            'qa_rejection_rate' => $this->rate($qaFailed, $qaCycles),
            'regression_rate'   => $this->rate($regressions, $created),
            'recurrence_rate'   => $this->rate($recurring, $created),
            'window'            => ['from' => $window['from']->toDateString(), 'to' => $window['to']->toDateString()],
        ];
    }

    /**
     * Defect trend: created vs resolved per bucket. Two series, because created
     * alone says nothing — a rising line is bad news or good reporting, and only
     * the pair tells you which.
     */
    public function trend(int $tenantId, SireUserIdentity $user, array $window, string $bucket = 'week', array $filters = []): array
    {
        $format = $bucket === 'month' ? '%Y-%m' : '%Y-%W';

        $created = $this->bucketed(
            $this->scoped($tenantId, $user, $filters)
                ->whereBetween('created_at', [$window['from'], $window['to']]),
            'created_at', $format,
        );

        $resolved = $this->bucketed(
            $this->scoped($tenantId, $user, $filters)
                ->whereIn('status', [SireStatus::CLOSED, SireStatus::PRODUCTION_VALIDATED])
                ->whereBetween('closed_at', [$window['from'], $window['to']]),
            'closed_at', $format,
        );

        $keys = $created->keys()->merge($resolved->keys())->unique()->sort()->values();

        return $keys->map(fn (string $key) => [
            'bucket'   => $key,
            'created'  => (int) ($created[$key] ?? 0),
            'resolved' => (int) ($resolved[$key] ?? 0),
        ])->all();
    }

    /**
     * Portable date bucketing. MySQL DATE_FORMAT and SQLite strftime differ, and
     * the whole test suite runs on SQLite while production is MySQL — a gap that
     * has already broken a deploy in this codebase.
     */
    private function bucketed($query, string $column, string $mysqlFormat): Collection
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        $expression = $driver === 'sqlite'
            ? sprintf("strftime('%s', %s)", str_replace(['%Y', '%m', '%W'], ['%Y', '%m', '%W'], $mysqlFormat), $column)
            : sprintf("DATE_FORMAT(%s, '%s')", $column, $mysqlFormat);

        return $query
            ->selectRaw("{$expression} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');
    }

    /** Modules with the highest defect counts. Where to look first, not a verdict. */
    public function topModules(int $tenantId, SireUserIdentity $user, array $window, int $limit = 10): array
    {
        return $this->scoped($tenantId, $user, [])
            ->whereNotNull('module')
            ->whereBetween('created_at', [$window['from'], $window['to']])
            ->selectRaw('module, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_regression = 1 THEN 1 ELSE 0 END) as regressions')
            ->selectRaw('SUM(CASE WHEN reopen_count > 0 THEN 1 ELSE 0 END) as reopened')
            ->groupBy('module')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'module'      => $row->module,
                'total'       => (int) $row->total,
                'regressions' => (int) $row->regressions,
                'reopened'    => (int) $row->reopened,
            ])->all();
    }

    /** Regressions grouped by the release that caused them. */
    public function regressionsByRelease(int $tenantId, SireUserIdentity $user, int $limit = 10): array
    {
        $counts = $this->scoped($tenantId, $user, [])
            ->where('is_regression', true)
            ->whereNotNull('caused_by_release_id')
            ->selectRaw('caused_by_release_id, COUNT(*) as total')
            ->groupBy('caused_by_release_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'caused_by_release_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $releases = Release::query()->forTenant($tenantId)
            ->whereIn('id', $counts->keys())
            ->get(['id', 'version', 'name', 'release_date'])
            ->keyBy('id');

        return $counts->map(fn (int $total, $releaseId) => [
            'release_id'   => (int) $releaseId,
            'version'      => $releases[$releaseId]->version ?? 'unknown',
            'name'         => $releases[$releaseId]->name ?? null,
            'release_date' => $releases[$releaseId]->release_date?->toDateString(),
            'regressions'  => $total,
        ])->values()->all();
    }

    /** Active recurrence groups, worst risk first. */
    public function recurringIssues(int $tenantId, int $limit = 10): array
    {
        $rank = "CASE recurrence_risk WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END";

        return RecurrenceGroup::query()
            ->forTenant($tenantId)
            ->where('is_closed', false)
            ->where('occurrence_count', '>=', 2)
            ->orderByRaw($rank)
            ->orderByDesc('occurrence_count')
            ->limit($limit)
            ->get(['id', 'reference', 'title', 'occurrence_count', 'recurrence_risk',
                   'latest_occurrence_at', 'average_interval_days', 'permanent_fix_status'])
            ->all();
    }

    /**
     * THE tenant boundary for every query above. Scoping is opt-in per query in
     * this codebase, so there is exactly one entry point and nothing bypasses it.
     */
    private function scoped(int $tenantId, SireUserIdentity $user, array $filters)
    {
        $query = $this->access->scopeVisible(Report::query()->forTenant($tenantId), $user);

        return $query
            ->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($filters['severity_id'] ?? null, fn ($q, $v) => $q->where('severity_id', (int) $v));
    }
}
