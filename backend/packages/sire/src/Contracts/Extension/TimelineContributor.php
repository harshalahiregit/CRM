<?php

namespace Sire\Contracts\Extension;

use Sire\Models\Report;

/**
 * SIRE — an extension point on the issue timeline.
 *
 * This interface is the whole reason the AI layer can exist without core knowing
 * about it. SireTimelineService merges system events, human comments and whatever
 * contributors are bound into the container. It imports THIS, never any
 * implementation — so `Sire\AI` can be deleted entirely and the
 * timeline still renders.
 *
 * Dependency inversion is doing real work here rather than being ceremony: without
 * it, showing an AI suggestion in the timeline would mean core referencing an AI
 * class, and "AI is optional" would stop being true the moment the feature shipped.
 *
 * CONTRACT
 *   - MUST NOT THROW. A contributor that fails must return an empty array; a
 *     timeline is not the place to discover that an optional subsystem is down.
 *   - MUST be tenant-scoped. Contributors run inside a request that has already
 *     established the tenant, and must not widen it.
 *   - MUST return entries whose `kind` is distinct from 'system' and 'comment', so
 *     a reader can tell a machine's proposal from a person's decision.
 */
interface TimelineContributor
{
    /**
     * @return array<int, array{
     *     id: string, kind: string, title: ?string, body: ?string,
     *     actor_name: ?string, at: mixed, editable: bool
     * }>
     */
    public function entriesFor(Report $report, $viewer): array;
}
