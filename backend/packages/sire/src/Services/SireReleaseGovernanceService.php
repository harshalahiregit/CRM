<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\Release;
use Sire\Models\ReleaseOverride;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireReleaseStatus;
use Sire\Support\SireStatus;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — release governance.
 *
 * WHAT THIS IS NOT: a deployment tool. There is no deployment platform in this
 * CRM to integrate with — deploys are a zip uploaded through the Plesk file
 * manager, with no CI, no pipeline and no git checkout on the live server. SIRE
 * records that a release happened, gates whether it *should*, and holds no
 * credential that could make one happen. `deployment_ref` is the seam for the day
 * a real pipeline exists.
 *
 * The lifecycle is in SireReleaseStatus. READY and BLOCKED are derived from the
 * gates and are never set by hand — you do not mark a release ready, you make its
 * gates pass, or you override them on the record.
 */
class SireReleaseGovernanceService
{
    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireReleaseGateService $gates,
        private readonly SireNotifier $notifier,
    ) {
    }

    // ------------------------------------------------------------- transitions

    public function apply(Release $release, string $action, SireUserIdentity $actor, array $payload = []): Release
    {
        $definition = SireReleaseStatus::TRANSITIONS[$action] ?? null;

        if ($definition === null) {
            throw new SireException("Unknown release action '{$action}'.");
        }

        if (! SireReleaseStatus::allows((string) $release->status, $action)) {
            throw new SireException(sprintf(
                'Cannot %s a release that is %s.',
                strtolower($definition['label']),
                SireReleaseStatus::label($release->status),
            ));
        }

        $this->access->assert($actor, $definition['capability']);

        foreach ($definition['requires'] ?? [] as $field) {
            if (blank($payload[$field] ?? null)) {
                throw new SireException('Please provide a '.str_replace('_', ' ', $field).'.');
            }
        }

        return match ($action) {
            'approve'         => $this->approve($release, $actor),
            'revoke_approval' => $this->revokeApproval($release, $actor),
            'release'         => $this->markReleased($release, $actor, $payload),
            'cancel'          => $this->cancel($release, $actor, $payload['reason']),
            'roll_back'       => $this->rollBack($release, $actor, $payload['reason']),
            default           => throw new SireException('Unhandled release action.'),
        };
    }

    private function approve(Release $release, SireUserIdentity $actor): Release
    {
        // Re-evaluate rather than trusting the cache. Between the dashboard
        // rendering and the button being pressed, someone may have reopened a
        // critical issue — and approving on a stale reading is precisely the
        // failure a gate engine exists to prevent.
        $evaluation = $this->gates->evaluate($release);

        if ($evaluation['status'] !== 'ready') {
            throw new SireException(sprintf(
                'This release is blocked by %d gate(s): %s. Resolve them, or record an emergency override.',
                $evaluation['blocking_failures'],
                implode(', ', $this->gates->failingGateKeys($evaluation)),
            ));
        }

        return DB::transaction(function () use ($release, $actor, $evaluation) {
            $release->fill([
                'status'      => SireReleaseStatus::APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $release->recordAudit(
                'Release approved',
                $actor,
                null,
                [
                    'action'          => 'approve',
                    'system'          => true,
                    // The gate state at the moment of approval, so the record says
                    // what was true then rather than what is true now.
                    'gate_state'      => $evaluation['status'],
                    'override_active' => $evaluation['override_active'],
                ],
            );

            $this->notifier->sendReleaseEvent($release, 'sire.release.approved', $actor);

            return $release->fresh();
        });
    }

    private function revokeApproval(Release $release, SireUserIdentity $actor): Release
    {
        return DB::transaction(function () use ($release, $actor) {
            $release->fill(['status' => SireReleaseStatus::BLOCKED, 'approved_by' => null, 'approved_at' => null])->save();
            $release->recordAudit('Release approval revoked', $actor, null, ['system' => true]);

            // Recompute immediately so the row does not sit at BLOCKED when the
            // gates are in fact fine.
            $this->gates->evaluate($release->fresh());

            return $release->fresh();
        });
    }

    private function markReleased(Release $release, SireUserIdentity $actor, array $payload): Release
    {
        return DB::transaction(function () use ($release, $actor, $payload) {
            $release->fill([
                'status'         => SireReleaseStatus::RELEASED,
                'released_at'    => now(),
                'released_by'    => $actor->id,
                'deployment_ref' => $payload['deployment_ref'] ?? $release->deployment_ref,
            ])->save();

            // Stamp what actually shipped. Only issues that reached the release
            // stage — a fix that missed the train has not shipped.
            $stamped = Report::query()
                ->forTenant($release->tenant_id)
                ->where('fixed_version_id', $release->id)
                ->whereNull('released_version_id')
                ->whereIn('status', [
                    SireStatus::READY_FOR_RELEASE, SireStatus::RELEASED, SireStatus::PRODUCTION_VALIDATED,
                ])
                ->update(['released_version_id' => $release->id]);

            $release->recordAudit('Release shipped', $actor, null, [
                'action' => 'release', 'issues_stamped' => $stamped, 'system' => true,
            ]);

            $this->notifier->sendReleaseEvent($release, 'sire.release.released', $actor);

            return $release->fresh();
        });
    }

    private function cancel(Release $release, SireUserIdentity $actor, string $reason): Release
    {
        return DB::transaction(function () use ($release, $actor, $reason) {
            $release->fill([
                'status'        => SireReleaseStatus::CANCELLED,
                'cancelled_by'  => $actor->id,
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
            ])->save();

            // A cancelled release cannot ship, so any override on it is spent.
            $this->revokeOverrides($release, $actor, 'Release cancelled');

            $release->recordAudit('Release cancelled', $actor, $reason, ['action' => 'cancel', 'system' => true]);

            return $release->fresh();
        });
    }

    private function rollBack(Release $release, SireUserIdentity $actor, string $reason): Release
    {
        return DB::transaction(function () use ($release, $actor, $reason) {
            $release->fill(['status' => SireReleaseStatus::ROLLED_BACK])->save();

            // These issues are not in production any more. Leaving the stamp would
            // put them in release notes for something customers never received.
            $cleared = Report::query()
                ->forTenant($release->tenant_id)
                ->where('released_version_id', $release->id)
                ->update(['released_version_id' => null]);

            $release->recordAudit('Release rolled back', $actor, $reason, [
                'action' => 'roll_back', 'issues_cleared' => $cleared, 'system' => true,
            ]);

            $this->notifier->sendReleaseEvent($release, 'sire.release.rolled_back', $actor);

            return $release->fresh();
        });
    }

    // ---------------------------------------------------------------- override

    /**
     * Record an emergency override.
     *
     * Three things make this governance rather than a bypass button:
     *
     *   1. A SEPARATE CAPABILITY. Whoever can approve a release cannot necessarily
     *      override its gates.
     *   2. IT NAMES THE GATES. A blanket "ignore everything" would make the
     *      register useless — the interesting question is always which check was
     *      skipped.
     *   3. IT SNAPSHOTS WHAT THE GATES SAID. Six months later, "we overrode the
     *      critical gate" is worth little next to "we overrode it while three
     *      critical issues were open".
     */
    public function override(Release $release, array $data, SireUserIdentity $actor): ReleaseOverride
    {
        $this->access->assert($actor, 'sire.release.override');

        if (! SireReleaseStatus::isOpen((string) $release->status)) {
            throw new SireException('Only a release that has not shipped can be overridden.');
        }
        if (! in_array($data['reason'] ?? null, ReleaseOverride::REASONS, true)) {
            throw new SireException('Choose a reason for the override.');
        }
        if (blank($data['justification'] ?? null)) {
            throw new SireException('An override needs a written justification. It will be read later.');
        }

        $evaluation = $this->gates->evaluate($release, persist: false);
        $failing = $this->gates->failingGateKeys($evaluation);
        $requested = array_values(array_unique($data['gates'] ?? []));

        if ($requested === []) {
            throw new SireException('Name the gates being overridden.');
        }

        $unknown = array_diff($requested, array_column($evaluation['gates'], 'key'));
        if ($unknown !== []) {
            throw new SireException('Unknown gate(s): '.implode(', ', $unknown).'.');
        }

        return DB::transaction(function () use ($release, $requested, $failing, $evaluation, $data, $actor) {
            // One active override per release attempt. A second supersedes the
            // first rather than stacking, so "which override let this through" has
            // one answer.
            $this->revokeOverrides($release, $actor, 'Superseded by a new override');

            $override = ReleaseOverride::create([
                'tenant_id'        => $release->tenant_id,  // explicit, never ambient
                'release_id'       => $release->id,
                'overridden_gates' => $requested,
                'gate_snapshot'    => $evaluation,
                'reason'           => $data['reason'],
                'justification'    => $data['justification'],
                'authorized_by'    => $actor->id,
                'authorized_at'    => now(),
            ]);

            $release->recordAudit(
                'Emergency release override authorised',
                $actor,
                $data['justification'],
                [
                    'action'           => 'override',
                    'system'           => true,
                    'overridden_gates' => $requested,
                    // Recorded separately: naming a gate that was passing anyway is
                    // not the same act as bypassing a real failure.
                    'gates_actually_failing' => array_values(array_intersect($requested, $failing)),
                    'reason'           => $data['reason'],
                    'override_id'      => $override->id,
                ],
            );

            // Re-evaluate so the release moves to READY in the same request.
            $this->gates->evaluate($release->fresh());

            $this->notifier->sendReleaseEvent($release, 'sire.release.overridden', $actor);

            return $override;
        });
    }

    public function revokeOverride(ReleaseOverride $override, SireUserIdentity $actor, string $reason): ReleaseOverride
    {
        $this->access->assert($actor, 'sire.release.override');

        if (! $override->isActive()) {
            throw new SireException('That override has already been revoked.');
        }

        $override->fill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();
        $override->release?->recordAudit('Release override revoked', $actor, $reason, ['system' => true]);

        if ($override->release) {
            $this->gates->evaluate($override->release);
        }

        return $override;
    }

    private function revokeOverrides(Release $release, SireUserIdentity $actor, string $reason): void
    {
        ReleaseOverride::query()
            ->forTenant($release->tenant_id)
            ->where('release_id', $release->id)
            ->active()
            ->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
    }
}
