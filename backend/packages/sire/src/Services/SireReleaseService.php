<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\Release;
use Sire\Support\SireReleaseStatus;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireStatus;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — releases and version mapping.
 *
 * An issue carries four version roles, and they answer four different questions:
 *   detected_version   where we first saw it
 *   affected_versions  everywhere it is present (json — display only)
 *   fixed_version      where the fix was merged
 *   released_version   where the fix actually shipped
 *
 * fixed and released are separate on purpose. A fix merged into 2026.4 that slips
 * the train has been fixed and NOT released, and conflating the two is how a
 * customer gets told a bug is gone before it is.
 */
class SireReleaseService
{
    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireRegressionService $regressions,
    ) {
    }

    public function create(int $tenantId, array $data, SireUserIdentity $actor): Release
    {
        $this->assertType($data['release_type'] ?? null);

        $clash = Release::query()->forTenant($tenantId)->where('version', $data['version'])->exists();
        if ($clash) {
            throw new SireException("Version {$data['version']} already exists in this workspace.");
        }

        return DB::transaction(function () use ($tenantId, $data, $actor) {
            $release = Release::create([
                'tenant_id'    => $tenantId,
                'version'      => $data['version'],
                'name'         => $data['name'] ?? null,
                'release_type' => $data['release_type'],
                'release_date' => $data['release_date'] ?? null,
                // BLOCKED, not 'planned'. A release starts closed and the gates open
                // it; 'planned' is not in SireReleaseStatus::ALL at all, so a release
                // created with it matched no governed transition and could never be
                // approved or shipped -- it simply sat there looking normal.
                'status'       => $data['status'] ?? SireReleaseStatus::BLOCKED,
                'owner_id'     => $data['owner_id'] ?? $actor->id,
                'summary'      => $data['summary'] ?? null,
            ]);

            $release->recordAudit('Release created', $actor, null, ['system' => true]);

            return $release;
        });
    }

    /**
     * Mark a release shipped and stamp every issue that went out with it.
     *
     * The stamp is applied to issues whose FIX landed in this release and which
     * have reached the release stage — not to everything pointed at it, because a
     * planned fix that missed the train has not shipped.
     */
    public function markReleased(Release $release, SireUserIdentity $actor): Release
    {
        $this->access->assert($actor, 'sire.release.manage');

        if ($release->status === 'released') {
            throw new SireException('That release is already marked as released.');
        }

        return DB::transaction(function () use ($release, $actor) {
            $release->fill([
                'status'      => 'released',
                'released_at' => now(),
                'released_by' => $actor->id,
            ])->save();

            $stamped = Report::query()
                ->forTenant($release->tenant_id)
                ->where('fixed_version_id', $release->id)
                ->whereNull('released_version_id')
                ->whereIn('status', [SireStatus::READY_FOR_RELEASE, SireStatus::RELEASED, SireStatus::PRODUCTION_VALIDATED])
                ->update(['released_version_id' => $release->id]);

            $release->recordAudit(
                'Release shipped',
                $actor,
                null,
                ['action' => 'released', 'issues_stamped' => $stamped, 'system' => true],
            );

            return $release->fresh();
        });
    }

    /**
     * Rolling back is a first-class outcome, not an edit. Issues shipped in a
     * rolled-back release lose their released_version stamp — they are not in
     * production any more, and leaving the stamp would put them in release notes
     * for something customers never received.
     */
    public function rollBack(Release $release, string $reason, SireUserIdentity $actor): Release
    {
        $this->access->assert($actor, 'sire.release.manage');

        if ($release->status !== 'released') {
            throw new SireException('Only a released version can be rolled back.');
        }

        return DB::transaction(function () use ($release, $reason, $actor) {
            $release->fill(['status' => 'rolled_back'])->save();

            $cleared = Report::query()
                ->forTenant($release->tenant_id)
                ->where('released_version_id', $release->id)
                ->update(['released_version_id' => null]);

            $release->recordAudit(
                'Release rolled back',
                $actor,
                $reason,
                ['action' => 'rolled_back', 'issues_cleared' => $cleared, 'system' => true],
            );

            return $release->fresh();
        });
    }

    /** Everything that shipped, ready for the release-notes generator. */
    public function shippedIssues(Release $release)
    {
        return Report::query()
            ->forTenant($release->tenant_id)
            ->where('released_version_id', $release->id)
            ->with(['category:id,code,name', 'severity:id,code,name', 'assignee:id,name'])
            ->orderBy('module')
            ->get();
    }

    private function assertType(?string $type): void
    {
        if (! in_array($type, Release::TYPES, true)) {
            throw new SireException('Release type must be one of: '.implode(', ', Release::TYPES).'.');
        }
    }
}
