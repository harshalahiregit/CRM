<?php

namespace Sire\Services;

use Sire\Models\Release;
use Sire\Models\ReleaseNote;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireReleaseClass;
use Sire\Support\SireReleaseStatus;
use Sire\Support\SireStatus;
use Illuminate\Support\Collection;

/**
 * SIRE — the release governance dashboard.
 *
 * One row per release, carrying every column the brief names. The counts and the
 * gate verdict come from the same place the gates themselves read, so the number
 * on the dashboard and the reason a release is blocked cannot disagree.
 *
 * Deliberately N+1-free on the hot path: counts are gathered with grouped
 * aggregate queries across ALL releases on the page, not per release. The
 * production database is shared by two deployments, and a governance dashboard
 * that fires forty queries per page load is the kind of thing that shows up as
 * somebody else's outage.
 */
class SireReleaseDashboardService
{
    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireReleaseGateService $gates,
    ) {
    }

    public function rows(int $tenantId, SireUserIdentity $user, array $filters = [], int $perPage = 25)
    {
        $page = Release::query()
            ->forTenant($tenantId)
            ->with('owner:id,name')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($filters['release_type'] ?? null, fn ($q, $v) => $q->where('release_type', $v))
            ->orderByRaw($this->statusOrder())
            ->orderByDesc('release_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $ids = collect($page->items())->pluck('id');

        if ($ids->isEmpty()) {
            return $page;
        }

        $counts = $this->countsFor($tenantId, $user, $ids);
        $notes = $this->noteStatusFor($tenantId, $ids);

        $page->getCollection()->transform(function (Release $release) use ($counts, $notes) {
            $c = $counts[$release->id] ?? [];

            // The cached gate verdict, with its age. A stale reading is shown as
            // stale rather than presented as current — the dashboard says when it
            // was computed, and the detail view recomputes on open.
            $gate = $release->gate_state;

            $release->setAttribute('governance', [
                'total_issues'        => $c['total'] ?? 0,
                'critical'            => $c['critical'] ?? 0,
                'high'                => $c['high'] ?? 0,
                'qa_passed'           => $c['qa_passed'] ?? 0,
                'qa_failed'           => $c['qa_failed'] ?? 0,
                'regression'          => $c['regression'] ?? 0,
                'recurring'           => $c['recurring'] ?? 0,
                'sla_breach'          => $c['sla_breach'] ?? 0,
                'by_class'            => $c['by_class'] ?? [],
                'release_notes'       => $notes[$release->id] ?? ['internal' => null, 'user' => null],
                'approval_status'     => $this->approvalStatus($release),
                'gate_status'         => $gate['status'] ?? null,
                'gate_blocking_fails' => $gate['blocking_failures'] ?? null,
                'gate_override_active' => $gate['override_active'] ?? false,
                'gate_evaluated_at'   => $release->gate_evaluated_at?->toIso8601String(),
                'gate_stale'          => $release->gate_evaluated_at === null
                    || $release->gate_evaluated_at->lt(now()->subMinutes(15)),
            ]);

            return $release;
        });

        return $page;
    }

    /** The full picture for one release, with the gates freshly evaluated. */
    public function detail(Release $release, SireUserIdentity $user): array
    {
        $evaluation = $this->gates->evaluate($release);
        $counts = $this->countsFor((int) $release->tenant_id, $user, collect([$release->id]))[$release->id] ?? [];

        return [
            'release'    => $release->fresh()->load('owner:id,name', 'releaseNotes'),
            'counts'     => $counts,
            'gates'      => $evaluation,
            'can_approve' => $evaluation['status'] === 'ready'
                && $release->status === SireReleaseStatus::READY,
            'failing_gates' => $this->gates->failingGateKeys($evaluation),
            'overrides'  => $release->tenant_id
                ? \Sire\Models\ReleaseOverride::query()
                    ->forTenant($release->tenant_id)
                    ->where('release_id', $release->id)
                    ->with('authorizer:id,name')
                    ->latest('authorized_at')
                    ->get()
                : collect(),
        ];
    }

    /**
     * Every count for every release on the page, in a handful of grouped queries
     * rather than one query per release per metric.
     *
     * @return array<int, array<string, mixed>>
     */
    private function countsFor(int $tenantId, SireUserIdentity $user, Collection $releaseIds): array
    {
        $base = fn () => $this->access
            ->scopeVisible(Report::query()->forTenant($tenantId), $user)
            ->where(function ($q) use ($releaseIds) {
                $q->whereIn('released_version_id', $releaseIds)
                    ->orWhereIn('fixed_version_id', $releaseIds);
            });

        // COALESCE so an issue counts against whichever release it belongs to,
        // preferring what actually shipped over what was merely planned.
        $key = 'COALESCE(released_version_id, fixed_version_id)';

        $out = [];

        $tally = function ($query, string $bucket) use (&$out, $key) {
            foreach ($query->selectRaw("{$key} as rid, COUNT(*) as total")->groupBy('rid')->get() as $row) {
                $out[(int) $row->rid][$bucket] = (int) $row->total;
            }
        };

        $tally((clone $base()), 'total');
        $tally((clone $base())->where('status', SireStatus::QA_PASSED), 'qa_passed');
        $tally((clone $base())->where('status', SireStatus::QA_FAILED), 'qa_failed');
        $tally((clone $base())->where('is_regression', true), 'regression');
        $tally((clone $base())->whereNotNull('recurrence_group_id'), 'recurring');
        $tally((clone $base())->where('priority', 'p1'), 'high');
        $tally(
            (clone $base())->where(function ($q) {
                $q->where('sla_ack_notified_state', 'breached')
                    ->orWhere('sla_resolve_notified_state', 'breached');
            }),
            'sla_breach',
        );

        // Critical is a severity, which lives in another table.
        foreach (
            (clone $base())
                ->join('sire_severities', 'sire_severities.id', '=', 'sire_reports.severity_id')
                ->where('sire_severities.code', 'critical')
                ->selectRaw("COALESCE(sire_reports.released_version_id, sire_reports.fixed_version_id) as rid, COUNT(*) as total")
                ->groupBy('rid')->get() as $row
        ) {
            $out[(int) $row->rid]['critical'] = (int) $row->total;
        }

        // Content mix — bugs / changes / improvements / security / performance.
        // Resolved in PHP because it is a three-step fallback (issue → category →
        // track) that SQL would express as nested COALESCE over a join, which is
        // harder to read and no faster at this row count.
        $classRows = (clone $base())
            ->with('category:id,release_class')
            ->get(['id', 'released_version_id', 'fixed_version_id', 'release_class', 'category_id', 'workflow_track']);

        foreach ($classRows as $report) {
            $rid = (int) ($report->released_version_id ?? $report->fixed_version_id);
            $class = $report->releaseClass();
            $out[$rid]['by_class'][$class] = ($out[$rid]['by_class'][$class] ?? 0) + 1;
        }

        // Fill the classes that scored zero so the UI renders a stable set.
        foreach ($out as $rid => $row) {
            foreach (SireReleaseClass::ALL as $class) {
                $out[$rid]['by_class'][$class] = $out[$rid]['by_class'][$class] ?? 0;
            }
        }

        return $out;
    }

    /** @return array<int, array{internal: ?string, user: ?string}> */
    private function noteStatusFor(int $tenantId, Collection $releaseIds): array
    {
        $out = [];

        foreach (
            ReleaseNote::query()->forTenant($tenantId)
                ->whereIn('release_id', $releaseIds)
                ->get(['release_id', 'audience', 'status']) as $note
        ) {
            $out[(int) $note->release_id][$note->audience] = $note->status;
        }

        return array_map(
            fn (array $row) => ['internal' => $row['internal'] ?? null, 'user' => $row['user'] ?? null],
            $out,
        );
    }

    private function approvalStatus(Release $release): string
    {
        return match ($release->status) {
            SireReleaseStatus::APPROVED    => 'approved',
            SireReleaseStatus::RELEASED    => 'approved',
            SireReleaseStatus::ROLLED_BACK => 'approved',
            SireReleaseStatus::CANCELLED   => 'cancelled',
            SireReleaseStatus::READY       => 'awaiting_approval',
            default                        => 'not_ready',
        };
    }

    /**
     * Sort so the releases needing attention float. Portable CASE rather than
     * MySQL FIELD(): the whole suite runs on SQLite, which has no FIELD().
     */
    private function statusOrder(): string
    {
        return "CASE status
            WHEN 'approved' THEN 1
            WHEN 'ready' THEN 2
            WHEN 'blocked' THEN 3
            WHEN 'released' THEN 4
            WHEN 'rolled_back' THEN 5
            ELSE 6 END";
    }
}
