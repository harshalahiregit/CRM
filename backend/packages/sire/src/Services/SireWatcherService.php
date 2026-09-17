<?php

namespace Sire\Services;

use Illuminate\Support\Collection;
use Sire\Contracts\SireUserProvider;
use Sire\Dto\SireUserIdentity;
use Sire\Models\Report;
use Sire\Models\ReportWatcher;

/**
 * SIRE — who else hears about an issue.
 *
 * Subscription only. Watching grants no sight of anything: reads still go through
 * the ordinary capability and tenant checks, so adding a watcher can never widen
 * what that person can see. It decides who is TOLD, nothing more.
 *
 * The reporter and the assignee are never stored here. They are told because of
 * the role they hold on the issue, and that role moves -- reassigning would leave
 * a stale watcher row behind, and the person would keep getting mail about an
 * issue that is no longer theirs. SireEvents::RECIPIENTS resolves those two from
 * the row itself; this table is for everybody else.
 */
class SireWatcherService
{
    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireUserProvider $users,
    ) {
    }

    /** Subscribe somebody. Idempotent -- watching twice is watching once. */
    public function watch(Report $report, int $userId, SireUserIdentity $actor): ReportWatcher
    {
        // Adding SOMEBODY ELSE is a small act of authority: it puts mail in their
        // inbox. Watching yourself needs nothing beyond being able to see the
        // issue, which the controller has already established.
        if ($userId !== (int) $actor->id) {
            $this->access->assert($actor, 'sire.report.triage', $report);
        }

        // ->forTenant() explicitly, even though the match array names the tenant
        // too. Scoping in this codebase is opt-in with no safety net, and
        // tests/tenant-scoping.test.mjs scans for the chain rather than trying to
        // reason about whether a particular call happens to be safe -- which is
        // the only way a scan like that can be trusted.
        return ReportWatcher::query()
            ->forTenant($report->tenant_id)
            ->firstOrCreate(
                [
                    'tenant_id' => (int) $report->tenant_id,
                    'report_id' => (int) $report->id,
                    'user_id'   => $userId,
                ],
                ['added_by' => $userId === (int) $actor->id ? null : (int) $actor->id],
            );
    }

    /**
     * Unsubscribe.
     *
     * Anyone may remove THEMSELVES -- being unable to stop a notification you did
     * not ask for is how people build inbox rules and stop reading any of it.
     * Removing someone else needs the same capability that added them.
     */
    public function unwatch(Report $report, int $userId, SireUserIdentity $actor): void
    {
        if ($userId !== (int) $actor->id) {
            $this->access->assert($actor, 'sire.report.triage', $report);
        }

        ReportWatcher::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->where('user_id', $userId)
            ->delete();
    }

    /** @return array<int, int> the user ids watching this issue */
    public function watcherIds(Report $report): array
    {
        return ReportWatcher::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Watchers with names, for the detail panel. */
    public function listFor(Report $report): Collection
    {
        $ids = $this->watcherIds($report);

        if ($ids === []) {
            return collect();
        }

        return collect($this->users->lookupMany((int) $report->tenant_id, $ids))
            ->map(fn (SireUserIdentity $u) => [
                'id'           => $u->id,
                'display_name' => $u->displayName,
                'role'         => $u->role,
            ])
            ->values();
    }
}
