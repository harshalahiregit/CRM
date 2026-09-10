<?php

namespace Sire\Services;

use Sire\Contracts\SireAuditProvider;
use Sire\Dto\SireAuditEvent;
use Sire\Dto\SireNote;
use Sire\Contracts\SireNotesProvider;
use Sire\Contracts\Extension\TimelineContributor;
use Sire\Models\Report;
use Illuminate\Support\Collection;

/**
 * SIRE — one timeline, two sources, no new tables.
 *
 * System events come from SireAuditProvider, written by RecordsSireAudit from the
 * workflow. User comments come from SireNotesProvider. Both resolve to whatever
 * the CRM has been wired to; this service merges them for display and never
 * writes to either.
 *
 * The two are kept distinguishable by `kind`, and by `editable`:
 *
 *   kind = 'system'   an event the workflow recorded. editable = false, ALWAYS.
 *                     There is no update or delete endpoint for these anywhere in
 *                     SIRE. A history someone can rewrite is not a history.
 *   kind = 'comment'  something a person typed. Editable by its author within the
 *                     existing NoteService rules.
 */
class SireTimelineService
{
    /**
     * @param  iterable<TimelineContributor>  $contributors  optional extensions
     *
     * Contributors are how anything outside core adds to a timeline WITHOUT core
     * referencing it. The default is none: unbind every contributor and this is
     * exactly the timeline it was before extensions existed.
     */
    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireAuditProvider $audit,
        private readonly SireNotesProvider $notes,
        private readonly iterable $contributors = [],
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> newest first
     */
    public function for(Report $report, $viewer, int $limit = 200): array
    {
        $events = $this->systemEvents($report, $limit);
        $comments = $this->comments($report, $viewer, $limit);
        $contributed = $this->contributed($report, $viewer);

        return $events
            ->merge($comments)
            ->merge($contributed)
            ->sortByDesc('at')
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * Entries from bound contributors. A contributor that misbehaves is dropped
     * rather than allowed to break the page — the timeline is core, and optional
     * extensions must not be able to take it down.
     */
    private function contributed(Report $report, $viewer): Collection
    {
        $entries = collect();

        foreach ($this->contributors as $contributor) {
            try {
                $entries = $entries->merge($contributor->entriesFor($report, $viewer));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $entries;
    }

    private function systemEvents(Report $report, int $limit): Collection
    {
        return collect($this->audit->for(Report::class, (int) $report->id, (int) $report->tenant_id, $limit))
            // The DTO renders itself. Nothing here decides what a system entry
            // looks like, which is why `editable` cannot be set to true by a
            // caller: SireAuditEvent::toTimelineEntry() hard-codes it false.
            ->map(fn (SireAuditEvent $event, int $i) => $event->toTimelineEntry('audit-'.$i.'-'.$report->id));
    }

    private function comments(Report $report, $viewer, int $limit): Collection
    {
        $mayModerate = $viewer !== null && $this->access->can($viewer, 'sire.comment.moderate', $report);

        return collect($this->notes->listFor($report, $viewer, $limit))
            ->map(fn (SireNote $note) => $note->toTimelineEntry($viewer, $mayModerate));
    }
}
