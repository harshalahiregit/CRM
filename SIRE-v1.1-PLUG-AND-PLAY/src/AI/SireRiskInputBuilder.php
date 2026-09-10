<?php

namespace Sire\AI;

use Sire\Models\Release;
use Sire\Models\Report;
use Sire\Models\WorkCycle;
use Sire\Support\SireStatus;
use Carbon\CarbonImmutable;

/**
 * SIRE — gather the numbers the risk engines reason about.
 *
 * Split from SireRiskEngine on purpose: this class talks to the database and is
 * therefore hard to test exactly; the engine is pure arithmetic and is tested
 * exactly, against a shared fixture. Keeping the query work here is what lets the
 * judgement live somewhere a decision table can pin it down.
 *
 * Read-only, and every query is tenant-scoped.
 */
class SireRiskInputBuilder
{
    /** How far back a module's track record is read. */
    private const HISTORY_DAYS = 180;

    /** Words that mean a fix deferred a defect rather than ending it. */
    private const WORKAROUND_HINTS = [
        'workaround', 'work around', 'temporary', 'temporarily', 'stopgap', 'band-aid',
        'bandaid', 'hotfix', 'patch over', 'manual', 'revert', 'disabled for now',
        'quick fix', 'mitigat',
    ];

    public function forRegression(Report $report): array
    {
        $since = CarbonImmutable::now()->subDays(self::HISTORY_DAYS);

        $moduleStats = $report->module
            ? Report::query()
                ->forTenant($report->tenant_id)
                ->where('module', $report->module)
                ->where('created_at', '>=', $since)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN is_regression = 1 THEN 1 ELSE 0 END) as regressions')
                ->first()
            : null;

        $total = (int) ($moduleStats->total ?? 0);
        $regressions = (int) ($moduleStats->regressions ?? 0);

        return [
            'module'                 => $report->module,
            'module_issue_count'     => $total,
            'module_regression_rate' => $total > 0 ? $regressions / $total : 0.0,
            'is_regression'          => (bool) $report->is_regression,
            'reopen_count'           => (int) $report->reopen_count,
            'severity_code'          => $report->severity?->code,
            'root_cause_category'    => $report->rootCause?->category,
            // Explicitly counted, so the engine can tell zero from unmeasured.
            'active_test_count'      => \Sire\Models\IssueTestCase::query()
                ->forTenant($report->tenant_id)
                ->where('report_id', $report->id)
                ->where('status', 'active')
                ->count(),
            'regression_test_required' => (bool) $report->requires_regression_test,
            'regression_test_done'     => $report->regression_tested_at !== null,
            'prior_release_caused_regressions' => $report->caused_by_release_id !== null,
        ];
    }

    public function forRecurrence(Report $report): array
    {
        $group = $report->recurrenceGroup;

        // How many separate releases have shipped a fix for this pattern. Several
        // is the clearest evidence that none addressed the cause.
        $distinctFixes = $group
            ? Report::query()
                ->forTenant($report->tenant_id)
                ->where('recurrence_group_id', $group->id)
                ->whereNotNull('released_version_id')
                ->distinct()
                ->count('released_version_id')
            : ($report->released_version_id ? 1 : 0);

        $sameCause = $report->rootCause?->category
            ? Report::query()
                ->forTenant($report->tenant_id)
                ->where('module', $report->module)
                ->where('id', '!=', $report->id)
                ->whereHas('rootCause', fn ($q) => $q->where('category', $report->rootCause->category))
                ->count()
            : 0;

        return [
            'occurrence_count'      => (int) ($group?->occurrence_count ?? ($report->reopen_count > 0 ? 2 : 1)),
            'reopen_count'          => (int) $report->reopen_count,
            'same_root_cause_count' => $sameCause,
            'distinct_fix_versions' => $distinctFixes,
            'workaround_signals'    => $this->workaroundSignals($report),
            'permanent_fix_status'  => $group?->permanent_fix_status,
        ];
    }

    public function forRelease(Release $release): array
    {
        $tenantId = (int) $release->tenant_id;
        $gate = $release->gate_state ?? [];
        $snapshot = $gate['snapshot'] ?? [];

        $inRelease = fn () => Report::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($release) {
                $q->where('released_version_id', $release->id)->orWhere('fixed_version_id', $release->id);
            });

        // QA pass rate from the work cycles of the issues in this release. Null
        // when QA has not run — unknown, which the engine treats as unknown rather
        // than as good news.
        $cycles = WorkCycle::query()
            ->forTenant($tenantId)
            ->where('phase', WorkCycle::PHASE_QA)
            ->whereIn('outcome', ['passed', 'failed'])
            ->whereIn('report_id', (clone $inRelease())->select('id'))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN outcome = 'passed' THEN 1 ELSE 0 END) as passed")
            ->first();

        $totalCycles = (int) ($cycles->total ?? 0);

        // Releases of the same type that caused regressions. "This kind of release
        // has bitten us before" is a different claim from "releases bite us".
        $priorProblems = Release::query()
            ->forTenant($tenantId)
            ->where('id', '!=', $release->id)
            ->where('release_type', $release->release_type)
            ->where('status', 'released')
            ->orderByDesc('released_at')
            ->limit(5)
            ->withCount('regressionsCaused')
            ->get()
            ->filter(fn (Release $r) => $r->regressions_caused_count > 0)
            ->count();

        return [
            'open_critical'   => (int) ($snapshot['open_critical'] ?? 0),
            'qa_failed'       => (int) ($snapshot['qa_failed'] ?? (clone $inRelease())->where('status', SireStatus::QA_FAILED)->count()),
            'regression_count' => (clone $inRelease())->where('is_regression', true)->count(),
            'recurring_count'  => (clone $inRelease())->whereNotNull('recurrence_group_id')->count(),
            'qa_pass_rate'     => $totalCycles > 0 ? ((int) $cycles->passed) / $totalCycles : null,
            'total_issues'     => (clone $inRelease())->count(),
            'prior_releases_caused_regressions' => $priorProblems,
        ];
    }

    /** Which workaround words actually appear, so the reason can quote them. */
    private function workaroundSignals(Report $report): array
    {
        $text = mb_strtolower(implode(' ', array_filter([
            $report->fix_summary, $report->investigation_notes, $report->description,
        ])));

        if ($text === '') {
            return [];
        }

        return array_values(array_filter(
            self::WORKAROUND_HINTS,
            fn (string $hint) => str_contains($text, $hint),
        ));
    }
}
