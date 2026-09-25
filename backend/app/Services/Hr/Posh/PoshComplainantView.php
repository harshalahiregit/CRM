<?php

namespace App\Services\Hr\Posh;

use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshFinding;
use App\Models\Hr\HrRequestMessage;

/**
 * Everything a complainant is allowed to see, and nothing else.
 *
 * ONE PLACE, ON PURPOSE. Every field the portal returns is written out by hand
 * here. Returning a model, or spreading a projection across two controllers,
 * is how the next column added to hr_posh_cases — a retention policy, an
 * anonymisation stamp, a respondent's employee id — arrives on the
 * complainant's screen without anybody deciding that it should.
 *
 * WHAT IS DELIBERATELY ABSENT: the narrative, internal notes, committee
 * deliberations, unpublished findings, the respondent, who is on the
 * committee, evidence, inquiry rounds, read-audit rows, notification rows, and
 * every internal identifier — case id, tenant id, committee id, member ids,
 * token fields. A complainant is told about their own complaint, not about the
 * people handling it.
 *
 * THE THREAD IS FILTERED ON kind = message EXPLICITLY. RequestThreadService's
 * asEmployee flag would be the obvious reuse and it is the wrong tool: it
 * excludes notes but admits events, and events are written by state changes
 * and say things like who opened an inquiry. POSH happens not to write thread
 * events today, so asEmployee would appear to work — right up until the first
 * one is added. Naming the one kind that is safe means a new kind is excluded
 * by default rather than included by accident.
 *
 * NO AUTHOR AND NO ROLE on a message. "Presiding Officer" identifies who sits
 * on the committee, which a complainant is not told — the complaint may concern
 * somebody who could end up on it.
 */
class PoshComplainantView
{
    /** The case, as the person who raised it may see it. */
    public function case(HrPoshCase $case): array
    {
        return [
            'reference'    => $case->reference,
            'status'       => $case->status,
            'status_label' => $this->statusLabel($case->status),

            // Their own complaint's clock. Dates, never deadlines.
            'submitted_at'         => $this->iso($case->complaint_received_at),
            'acknowledged_at'      => $this->iso($case->acknowledged_at),
            'inquiry_started_at'   => $this->iso($case->inquiry_started_at),
            'inquiry_completed_at' => $this->iso($case->inquiry_completed_at),

            // Set only once an inquiry has actually concluded.
            'outcome' => $case->outcome,

            // Null until somebody publishes. Phase 3c owns that decision and
            // this reads its result — there is no second publication rule here.
            'findings' => $this->findings($case),
        ];
    }

    /**
     * The messages addressed to the complainant, oldest first.
     *
     * Body and time. Nothing identifies who wrote it, and attachments are not
     * loaded at all: evidence belongs to the committee, and what a complainant
     * may be shown of it is a decision nobody has made.
     */
    public function thread(HrPoshCase $case): array
    {
        return HrRequestMessage::query()
            ->where('tenant_id', $case->tenant_id)
            ->where('subject_type', $case->getMorphClass())
            ->where('subject_id', $case->getKey())
            ->where('kind', HrRequestMessage::KIND_MESSAGE)
            ->orderBy('id')
            ->get(['id', 'body', 'created_at'])
            ->map(fn (HrRequestMessage $m) => [
                'body'       => $m->body,
                'created_at' => $this->iso($m->created_at),
            ])
            ->values()->all();
    }

    /* ── internals ────────────────────────────────────────────────────── */

    /**
     * The published finding, or null.
     *
     * Gated on published_at, not on status: a finding can be recorded — the
     * committee content with its wording — long before anybody decides it
     * should be seen, and recorded is not published.
     */
    private function findings(HrPoshCase $case): ?array
    {
        if (! $case->findings_published_at) {
            return null;
        }

        $finding = HrPoshFinding::where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->whereNotNull('published_at')
            ->latest('id')
            ->first();

        if (! $finding) {
            return null;
        }

        return [
            'summary'        => $finding->summary,
            'recommendation' => $finding->recommendation,
            'published_at'   => $this->iso($finding->published_at),
        ];
    }

    /**
     * Plain words for the status.
     *
     * The raw key is sent too, so a future screen can branch on something
     * stable rather than on prose.
     */
    private function statusLabel(?string $status): string
    {
        return match ($status) {
            HrPoshCase::STATUS_RECEIVED         => 'Received',
            HrPoshCase::STATUS_UNDER_INQUIRY    => 'Under inquiry',
            HrPoshCase::STATUS_INQUIRY_COMPLETE => 'Inquiry complete',
            HrPoshCase::STATUS_CLOSED           => 'Closed',
            HrPoshCase::STATUS_WITHDRAWN        => 'Withdrawn',
            default                             => 'In progress',
        };
    }

    private function iso($value): ?string
    {
        return $value ? $value->toIso8601String() : null;
    }
}
