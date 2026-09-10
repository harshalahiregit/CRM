<?php

namespace Sire\Services;

use Sire\Models\Release;
use Sire\Models\ReleaseOverride;
use Sire\Models\Report;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\SireReleaseStatus;
use Sire\Support\SireStatus;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — release gates.
 *
 * Direct port of tests/reference/releaseGates.mjs; both run
 * fixtures/release-gates-cases.json.
 *
 * A gate engine earns a shared decision table because its failure mode is silent
 * and expensive. A gate that quietly passes lets a broken release ship, and nobody
 * finds out from the dashboard — it says READY.
 *
 * CONFIGURATION uses the existing settings store. One key, no new table:
 *
 *   sire.release.gates = [
 *     { "key": "no_open_critical", "enabled": true, "blocking": true,
 *       "config": { "severities": ["critical"] } },
 *     ...
 *   ]
 *
 * `blocking: false` makes a gate advisory: it still reports what it found, but it
 * does not stop the release. That is the axis most worth having — teams disagree
 * about which checks are hard stops far more often than about what to check.
 */
class SireReleaseGateService
{
    public const PASS       = 'pass';
    public const FAIL       = 'fail';
    public const SKIPPED    = 'skipped';
    public const OVERRIDDEN = 'overridden';
    public const UNKNOWN    = 'unknown';

    public const DEFAULT_GATES = [
        ['key' => 'no_open_critical',            'enabled' => true, 'blocking' => true, 'config' => ['severities' => ['critical']]],
        ['key' => 'qa_failures_resolved',        'enabled' => true, 'blocking' => true],
        ['key' => 'approvals_complete',          'enabled' => true, 'blocking' => true],
        ['key' => 'regression_testing_complete', 'enabled' => true, 'blocking' => true],
    ];

    public const LABELS = [
        'no_open_critical'            => 'No open critical issues',
        'qa_failures_resolved'        => 'No unresolved QA failures',
        'approvals_complete'          => 'Required approvals complete',
        'regression_testing_complete' => 'Mandatory regression testing complete',
    ];

    public function __construct(private readonly SireSettingsProvider $settings)
    {
    }

    // -------------------------------------------------------------- evaluation

    /**
     * Evaluate a release and cache the result on the row.
     *
     * The gates are recomputed, never stored as truth. `gate_state` is a cache so
     * the dashboard can render forty releases without running forty evaluations,
     * and it carries its timestamp so a stale reading is visible rather than
     * assumed current.
     */
    public function evaluate(Release $release, bool $persist = true): array
    {
        $snapshot = $this->snapshot($release);
        $override = $this->activeOverride($release);

        $result = $this->evaluateSnapshot(
            $this->gatesFor((int) $release->tenant_id),
            $snapshot,
            $override?->overridden_gates,
        );

        $result['snapshot'] = $snapshot;
        $result['override'] = $override ? [
            'id'             => $override->id,
            'reason'         => $override->reason,
            'authorized_by'  => $override->authorizer?->name,
            'authorized_at'  => $override->authorized_at?->toIso8601String(),
            'gates'          => $override->overridden_gates,
        ] : null;

        if ($persist && SireReleaseStatus::isOpen((string) $release->status)) {
            // Only the derived pair is recomputed. An approved release does not
            // silently fall back to blocked because someone reopened an issue —
            // that needs a human to revoke the approval, and the dashboard shows
            // the failing gate either way.
            $release->gate_state = $result;
            $release->gate_evaluated_at = now();

            if (in_array($release->status, SireReleaseStatus::GATE_DERIVED, true)) {
                $release->status = $result['status'] === 'ready'
                    ? SireReleaseStatus::READY
                    : SireReleaseStatus::BLOCKED;
            }

            $release->saveQuietly();
        }

        return $result;
    }

    /** Pure. Given gates, a snapshot and an override, decide. */
    public function evaluateSnapshot(array $gates, array $snapshot, ?array $overriddenKeys = null): array
    {
        $overridden = array_flip($overriddenKeys ?? []);
        $results = [];

        foreach ($gates as $gate) {
            $key = $gate['key'];
            $blocking = ($gate['blocking'] ?? true) !== false;

            if (($gate['enabled'] ?? true) === false) {
                $results[] = $this->result($key, self::SKIPPED, $blocking, 'Disabled in settings', 0);

                continue;
            }

            $evaluated = $this->runGate($key, $snapshot, $gate['config'] ?? null);

            if ($evaluated === null) {
                // A configured gate this build cannot evaluate must BLOCK. The
                // alternative is a typo in settings silently disabling governance.
                $results[] = $this->result($key, self::UNKNOWN, $blocking, 'Unknown gate — this build cannot evaluate it', 0);

                continue;
            }

            $status = $evaluated['ok']
                ? self::PASS
                : (isset($overridden[$key]) ? self::OVERRIDDEN : self::FAIL);

            $results[] = $this->result($key, $status, $blocking, $evaluated['detail'], $evaluated['count']);
        }

        $blockingFailures = count(array_filter(
            $results,
            fn (array $r) => $r['blocking'] && in_array($r['status'], [self::FAIL, self::UNKNOWN], true),
        ));

        $advisoryFailures = count(array_filter(
            $results,
            fn (array $r) => ! $r['blocking'] && $r['status'] === self::FAIL,
        ));

        return [
            'gates'             => $results,
            'status'            => $blockingFailures === 0 ? 'ready' : 'blocked',
            'blocking_failures' => $blockingFailures,
            'advisory_failures' => $advisoryFailures,
            // True only when the override is actually load-bearing. One that names
            // a passing gate is still recorded, but the dashboard must not imply
            // the release shipped on an override when it did not.
            'override_active'   => (bool) array_filter($results, fn (array $r) => $r['status'] === self::OVERRIDDEN),
            'ungoverned'        => $gates === [],
            'empty'             => (int) ($snapshot['total_issues'] ?? 0) === 0,
        ];
    }

    /** @return array{ok: bool, detail: string, count: int}|null */
    private function runGate(string $key, array $s, ?array $config): ?array
    {
        return match ($key) {
            'no_open_critical' => (function () use ($s, $config) {
                $severities = $config['severities'] ?? ['critical'];
                $count = isset($s['open_by_severity'])
                    ? array_sum(array_map(fn ($x) => $s['open_by_severity'][$x] ?? 0, $severities))
                    : (int) ($s['open_critical'] ?? 0);

                return [
                    'ok' => $count === 0,
                    'count' => $count,
                    'detail' => $count === 0
                        ? 'No open '.implode('/', $severities).' issues'
                        : $count.' open '.implode('/', $severities).' issue'.($count === 1 ? '' : 's'),
                ];
            })(),

            'qa_failures_resolved' => (function () use ($s) {
                $count = (int) ($s['qa_failed'] ?? 0);

                return ['ok' => $count === 0, 'count' => $count,
                        'detail' => $count === 0 ? 'No unresolved QA failures'
                            : $count.' issue'.($count === 1 ? '' : 's').' sitting in QA Failed'];
            })(),

            'approvals_complete' => (function () use ($s) {
                $count = (int) ($s['pending_approvals'] ?? 0);

                return ['ok' => $count === 0, 'count' => $count,
                        'detail' => $count === 0 ? 'All approvals decided'
                            : $count.' approval'.($count === 1 ? '' : 's').' still pending'];
            })(),

            'regression_testing_complete' => (function () use ($s) {
                $required = (int) ($s['regression_required'] ?? 0);
                $tested = (int) ($s['regression_tested'] ?? 0);
                $outstanding = max(0, $required - $tested);

                // Nothing to test is a pass, not a division by zero.
                return ['ok' => $outstanding === 0, 'count' => $outstanding,
                        'detail' => $required === 0 ? 'No regression testing required'
                            : "{$tested}/{$required} regression tests complete"];
            })(),

            default => null,
        };
    }

    private function result(string $key, string $status, bool $blocking, string $detail, int $count): array
    {
        return [
            'key'      => $key,
            'label'    => self::LABELS[$key] ?? $key,
            'status'   => $status,
            'blocking' => $blocking,
            'detail'   => $detail,
            'count'    => $count,
        ];
    }

    // ---------------------------------------------------------------- snapshot

    /**
     * Count everything the gates need, in one pass per question.
     *
     * Every query is tenant-scoped through ->forTenant(); scoping is opt-in in
     * this codebase and a gate reading another tenant's counts would be both a
     * leak and nonsense.
     */
    public function snapshot(Release $release): array
    {
        $tenantId = (int) $release->tenant_id;

        $inRelease = fn () => Report::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($release) {
                // An issue counts toward a release if it is going out in it or is
                // slated to. Both matter to the gates: something still open and
                // slated is exactly what should block.
                $q->where('released_version_id', $release->id)
                    ->orWhere('fixed_version_id', $release->id);
            });

        $openBySeverity = (clone $inRelease())
            ->whereNotIn('sire_reports.status', SireStatus::TERMINAL)
            ->join('sire_severities', 'sire_severities.id', '=', 'sire_reports.severity_id')
            ->groupBy('sire_severities.code')
            ->pluck(DB::raw('COUNT(*)'), 'sire_severities.code')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'total_issues'        => (clone $inRelease())->count(),
            'open_by_severity'    => $openBySeverity,
            'open_critical'       => $openBySeverity['critical'] ?? 0,
            'qa_failed'           => (clone $inRelease())->where('status', SireStatus::QA_FAILED)->count(),
            'pending_approvals'   => $this->pendingApprovals($release),
            'regression_required' => (clone $inRelease())->where('requires_regression_test', true)->count(),
            'regression_tested'   => (clone $inRelease())->where('requires_regression_test', true)
                                        ->whereNotNull('regression_tested_at')->count(),
        ];
    }

    /**
     * Change requests in the release that have not cleared their approval, plus
     * any pending row in the shared approval register.
     *
     * Counts BOTH: a change request sitting mid-approval by its status, and any
     * undecided row in the approval register. The two can differ — a request
     * approved in the register but not yet transitioned is still work in flight —
     * and a gate that saw only one of them would pass a release that should wait.
     */
    private function pendingApprovals(Release $release): int
    {
        $inRelease = Report::query()
            ->forTenant($release->tenant_id)
            ->where(function ($q) use ($release) {
                $q->where('released_version_id', $release->id)->orWhere('fixed_version_id', $release->id);
            })
            ->select('id');

        $registered = \Sire\Models\ReportApproval::query()
            ->forTenant($release->tenant_id)
            ->pending()
            ->where('subject_type', \Sire\Models\ReportApproval::SUBJECT_REPORT)
            ->whereIn('subject_id', $inRelease)
            ->count();

        return $registered + Report::query()
            ->forTenant($release->tenant_id)
            ->where(function ($q) use ($release) {
                $q->where('released_version_id', $release->id)->orWhere('fixed_version_id', $release->id);
            })
            ->whereIn('status', [SireStatus::APPROVAL, SireStatus::BUSINESS_REVIEW, SireStatus::IMPACT_ANALYSIS])
            ->count();
    }

    // ------------------------------------------------------------ configuration

    public function gatesFor(int $tenantId): array
    {
        $configured = $this->settings->get($tenantId, 'sire.release.gates', null);

        // Absent means "use the defaults". An explicitly empty array means "no
        // governance", which is a legitimate thing to configure and is reported
        // as `ungoverned` rather than shown as a row of confident ticks.
        if ($configured === null) {
            return self::DEFAULT_GATES;
        }

        return is_array($configured) ? array_values($configured) : self::DEFAULT_GATES;
    }

    public function activeOverride(Release $release): ?ReleaseOverride
    {
        return ReleaseOverride::query()
            ->forTenant($release->tenant_id)
            ->where('release_id', $release->id)
            ->active()
            ->with('authorizer:id,name')
            ->latest('authorized_at')
            ->first();
    }

    /** The blocking gates an override would have to name to unblock this release. */
    public function failingGateKeys(array $evaluation): array
    {
        return array_values(array_map(
            fn (array $g) => $g['key'],
            array_filter(
                $evaluation['gates'],
                fn (array $g) => $g['blocking'] && in_array($g['status'], [self::FAIL, self::UNKNOWN], true),
            ),
        ));
    }
}
