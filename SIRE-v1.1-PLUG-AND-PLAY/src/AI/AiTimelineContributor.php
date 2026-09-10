<?php

namespace Sire\AI;

use Sire\Contracts\Extension\TimelineContributor;
use Sire\Models\AiSuggestion;
use Sire\Models\Report;
use Sire\Support\Ai\AiCapability;
use Throwable;

/**
 * SIRE AI — suggestions on the issue timeline.
 *
 * Bound into the container as a TimelineContributor. Core never references this
 * class; it asks for contributors and gets whatever is bound. Unbind it and the
 * timeline is exactly what it was before AI existed.
 *
 * Every entry is `kind: 'ai_suggestion'` and `editable: false`. A reader must be
 * able to tell a machine's proposal from a person's decision and from a workflow
 * event without knowing the schema — requirement 4, made visible rather than
 * merely true.
 */
class AiTimelineContributor implements TimelineContributor
{
    public function entriesFor(Report $report, $viewer): array
    {
        try {
            return AiSuggestion::query()
                ->forTenant($report->tenant_id)
                ->where('subject_type', AiCapability::SUBJECT_REPORT)
                ->where('subject_id', $report->id)
                ->with('decider:id,name')
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (AiSuggestion $s) => [
                    'id'         => 'ai-'.$s->id,
                    'kind'       => $s->timelineKind(),
                    'capability' => $s->capability,
                    'title'      => $s->capabilityLabel(),
                    'body'       => data_get($s->evidence, 'summary'),
                    'confidence' => $s->confidence,
                    'status'     => $s->status,
                    // Attribution is to the MODEL, not to a person. An AI
                    // suggestion showing a human's name would be the exact
                    // confusion requirement 4 exists to prevent.
                    'actor_name' => trim(($s->provider ?? 'AI').' '.($s->model ?? '')),
                    'decided_by' => $s->decider?->name,
                    'decided_at' => $s->decided_at,
                    'at'         => $s->created_at,
                    'editable'   => false,
                ])
                ->all();
        } catch (Throwable $e) {
            // A timeline is not the place to discover that an optional subsystem
            // is down. The issue still renders; the suggestions simply are not there.
            report($e);

            return [];
        }
    }
}
