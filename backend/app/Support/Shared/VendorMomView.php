<?php

namespace App\Support\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * The minutes of a meeting, as one shape, whichever engine they came from.
 *
 * Both vendor portals show the distributed minutes on the same screen, but the
 * two engines name the same things differently — the shared one calls them
 * `momItems`, `decisions` and `issues`, Purchase calls them `actionItems`,
 * `momDecisions` and `momIssues`. Each controller simply handed its own models
 * to the browser, so the one component reading them was reading keys that only
 * half of them ever sent.
 *
 * The visible result was a Purchase vendor opening their minutes and finding
 * only the agenda: every action item and every decision was present in the
 * response, under names the screen was not looking for, and silently dropped.
 * Issues were dropped for both, and so was the minutes text itself.
 *
 * Rather than teach the screen two vocabularies — which leaves the next
 * addition to make the same mistake — the translation happens here, once, and
 * both portals answer in the same words. The engines keep their own tables,
 * their own models and their own column names; only what reaches the vendor is
 * agreed.
 */
final class VendorMomView
{
    /**
     * @param  Model  $meeting  a KickoffMeeting or PurchaseKickoffMeeting
     * @param  bool  $momDocument  is there a minutes document the vendor may download?
     */
    public static function for(Model $meeting, bool $momDocument): array
    {
        return [
            'meeting' => [
                'id' => $meeting->id,
                'reference' => $meeting->reference,
                'meeting_no' => $meeting->meeting_no,
                'title' => $meeting->title,
                'meeting_type_label' => $meeting->meeting_type_label,
                'status' => $meeting->status,
                'mom_status' => $meeting->mom_status,
                'scheduled_at' => optional($meeting->scheduled_at)->toDateTimeString(),
                'location' => $meeting->location,
                'mode' => $meeting->mode,
                // The "Meeting Details" block the PDF prints. Carried so the
                // screen and the document say the same things about the same
                // meeting, rather than the screen being a thinner version.
                'chairperson' => $meeting->chairperson,
                'coordinator' => $meeting->coordinator,
                'organizer' => $meeting->organizer,
                'department' => $meeting->department,
                'category' => $meeting->category,
                'duration_minutes' => $meeting->duration_minutes,
                // What the call actually did, so the minutes carry the same
                // record of it that everything else now shows.
                'actual_start_at' => optional($meeting->actual_start_at)->toIso8601String(),
                'actual_end_at' => optional($meeting->actual_end_at)->toIso8601String(),
                'held_minutes' => $meeting->held_minutes,
            ],

            // The free-text minutes. Written in the meeting room, approved and
            // distributed with everything else — and never once shown to the
            // person they were distributed to.
            'minutes' => $meeting->minutes,

            /*
             * The agenda as it was actually written.
             *
             * A meeting's agenda can be free TEXT on the meeting itself, a list
             * of structured rows, or both — the PDF renders whichever exist. This
             * read only the rows, so a meeting whose agenda was typed as a
             * paragraph showed the vendor "No agenda was recorded" while the PDF
             * beside it printed the agenda in full. Same for the participants,
             * which the PDF lists with their attendance and this did not carry at
             * all.
             */
            'agenda_text' => $meeting->agenda,

            'participants' => self::rows($meeting, ['attendees', 'participants'], fn ($a) => [
                'name' => $a->name,
                'organisation' => $a->organisation ?? $a->organization ?? null,
                'designation' => $a->designation,
                'role' => $a->role,
                'attended' => (bool) $a->attended,
                'attendance_status' => $a->attendance_status,
            ]),

            'agenda' => self::rows($meeting, ['agendaItems'], fn ($a) => [
                'item' => $a->item,
                'description' => $a->description,
                'discussion' => $a->discussion,
                'decision' => $a->decision,
                'owner' => $a->owner_names,
            ]),

            'actions' => self::rows($meeting, ['momItems', 'actionItems'], fn ($a) => [
                'ref' => $a->action_ref,
                'description' => $a->description,
                'owner' => $a->responsible_names ?: $a->responsible_org,
                'target_date' => optional($a->target_date)->toDateString(),
                'status' => $a->status,
                'priority' => $a->priority,
                'remark' => $a->remark,
            ]),

            'decisions' => self::rows($meeting, ['decisions', 'momDecisions'], fn ($d) => [
                'ref' => $d->decision_ref,
                'decision' => $d->decision,
                'decided_by' => $d->decided_by_names,
                'impact' => $d->impact,
                'effective_date' => optional($d->effective_date)->toDateString(),
                'status' => $d->status,
            ]),

            'issues' => self::rows($meeting, ['issues', 'momIssues'], fn ($i) => [
                'ref' => $i->issue_ref,
                'title' => $i->title,
                'description' => $i->description,
                'category' => $i->category,
                'severity' => $i->severity,
                'owner' => $i->owner_names,
                'due_date' => optional($i->due_date)->toDateString(),
                'status' => $i->status,
            ]),

            'documents' => self::rows($meeting, ['documents'], fn ($d) => [
                'id' => $d->id,
                'label' => $d->label,
                'original_name' => $d->original_name,
                'size' => $d->size,
            ]),

            // Whether the minutes DOCUMENT can be downloaded, as opposed to the
            // structured content above. Until this existed the approved and
            // distributed PDF was readable by administrators only — the vendor
            // it had just been distributed to had no way to open it.
            'mom_document_available' => $momDocument,
            'distributed_at' => optional($meeting->mom_distributed_at)->toIso8601String(),
        ];
    }

    /**
     * Read whichever of these relations this engine actually has.
     *
     * Named as a list rather than resolved from the class, so adding a third
     * engine means adding its word here and nothing else.
     */
    private static function rows(Model $meeting, array $candidates, callable $map): array
    {
        foreach ($candidates as $relation) {
            if (! method_exists($meeting, $relation)) {
                continue;
            }

            return $meeting->{$relation}()->get()->map($map)->values()->all();
        }

        return [];
    }
}
