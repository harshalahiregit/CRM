<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireStatus;
use Illuminate\Support\Collection;

/**
 * SIRE — duplicate management.
 *
 * Direct port of tests/reference/duplicates.mjs; both run the same rules and the
 * JS side is unit-tested.
 *
 * Two things the brief insists on, and both are structural rather than optional:
 *
 *   NOTHING IS DELETED. Marking a duplicate is a status transition to `duplicate`
 *   plus a pointer. The row keeps its number, its comments, its attachments, its
 *   audit trail and its reporter. Someone searching for what they filed still
 *   finds it, and still sees where the work went.
 *
 *   HISTORY IS PRESERVED on both sides. The canonical issue records that it
 *   absorbed another; the duplicate records where it went. Neither is edited.
 */
class SireDuplicateService
{
    /** Guards against a pathological chain rather than looping forever. */
    private const MAX_CHAIN = 32;

    public function __construct(private readonly SireAccessService $access)
    {
    }

    /**
     * Walk to the end of the duplicate chain — the issue people should actually
     * be reading.
     *
     * @return array{canonical: Report, path: array<int, int>, cycle: bool}
     */
    public function resolveCanonical(Report $report): array
    {
        $path = [(int) $report->id];
        $seen = [(int) $report->id => true];
        $current = $report;

        for ($step = 0; $step < self::MAX_CHAIN; $step++) {
            $nextId = $current->duplicate_of_id ? (int) $current->duplicate_of_id : null;

            if ($nextId === null) {
                return ['canonical' => $current, 'path' => $path, 'cycle' => false];
            }
            if (isset($seen[$nextId])) {
                // Existing data can be broken even when new writes are guarded.
                // Report it; do not spin.
                return ['canonical' => $current, 'path' => [...$path, $nextId], 'cycle' => true];
            }

            $next = Report::query()->forTenant($report->tenant_id)->find($nextId);
            if ($next === null) {
                return ['canonical' => $current, 'path' => $path, 'cycle' => false];
            }

            $seen[$nextId] = true;
            $path[] = $nextId;
            $current = $next;
        }

        return ['canonical' => $current, 'path' => $path, 'cycle' => true];
    }

    /**
     * Validate a proposed duplicate link and return the id it should actually
     * point at.
     *
     * Chains are FLATTENED on write: marking A a duplicate of B, where B already
     * duplicates C, links A straight to C. Reads then stay one hop.
     *
     * @throws SireException when the link would loop or is nonsense
     */
    public function resolveTargetFor(Report $report, int $targetId): int
    {
        if ((int) $report->id === $targetId) {
            throw new SireException('An issue cannot be a duplicate of itself.');
        }

        $target = Report::query()->forTenant($report->tenant_id)->find($targetId);
        if ($target === null) {
            // 404-shaped information hiding is the controller's job; here the
            // caller has simply chosen something that is not theirs to choose.
            throw new SireException('That issue could not be found in your workspace.');
        }

        $resolved = $this->resolveCanonical($target);

        if ($resolved['cycle']) {
            throw new SireException(sprintf(
                'The target issue is part of a broken duplicate chain (%s). Fix that first.',
                implode(' → ', $resolved['path']),
            ));
        }

        if ((int) $resolved['canonical']->id === (int) $report->id) {
            throw new SireException(sprintf(
                'That would create a loop: %s.',
                implode(' → ', [...$resolved['path'], $report->id]),
            ));
        }

        return (int) $resolved['canonical']->id;
    }

    /**
     * Every issue that resolves to this one, however long the chain. Used by the
     * detail view to show "3 other reports of this".
     */
    public function membersOf(Report $canonical): Collection
    {
        return Report::query()
            ->forTenant($canonical->tenant_id)
            ->where('duplicate_of_id', $canonical->id)
            ->with(['reporter:id,name'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * A recurrence group member must be a real occurrence. A duplicate is not one
     * — it is the same occurrence written down twice — so counting it would
     * inflate the number the whole recurrence engine exists to report.
     */
    public function assertNotDuplicate(Report $report): void
    {
        if ($report->status === SireStatus::DUPLICATE || $report->duplicate_of_id !== null) {
            throw new SireException(
                'A duplicate is the same occurrence reported twice, not a separate one. '
                .'Add the issue it duplicates to the recurrence group instead.',
            );
        }
    }

    /**
     * Record the link on BOTH sides of the relationship, so neither issue reads
     * as though it lost information.
     */
    public function recordMerge(Report $duplicate, Report $canonical, SireUserIdentity $actor): void
    {
        $duplicate->recordAudit(
            "Marked as duplicate of {$canonical->report_number}",
            $actor,
            null,
            ['action' => 'mark_duplicate', 'canonical_id' => $canonical->id, 'system' => true],
        );

        $canonical->recordAudit(
            "Absorbed duplicate {$duplicate->report_number}",
            $actor,
            null,
            ['action' => 'absorbed_duplicate', 'duplicate_id' => $duplicate->id, 'system' => true],
        );
    }
}
