<?php

namespace App\Services\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Saves what was written in the live meeting room.
 *
 * The room runs the video and the meeting record side by side, so the notes are
 * typed while the meeting is happening rather than reconstructed afterwards
 * from memory. It writes two things: the discussion and decision against each
 * agenda point, and the meeting-level minutes.
 *
 * Why this is its own small service rather than a route into the existing
 * update endpoints:
 *
 *  - Neither engine's `update` accepts `minutes` at all — the only path to that
 *    column was the transition to Completed, which is the wrong moment. Notes
 *    are taken DURING the meeting; the room autosaves every few seconds.
 *  - The two engines write agenda notes through different doors (the shared one
 *    takes the whole agenda array on the meeting's PUT, Purchase takes one item
 *    at a time), so a single screen driving both would have needed two code
 *    paths and a different number of requests each. Here it is one call either
 *    way, and one transaction.
 *
 * Deliberately narrow: it touches only `minutes` and the two note fields on
 * agenda rows it can already see on this meeting. It cannot rename an agenda
 * item, add or remove one, or alter anything else about the meeting — a screen
 * whose job is note-taking should not be able to reshape the record.
 */
class MeetingRoomNotes
{
    /**
     * @param  Model  $meeting  a KickoffMeeting or PurchaseKickoffMeeting
     * @param  array  $agenda   [['id' => int, 'discussion' => ?string, 'decision' => ?string], ...]
     * @return array{agenda_saved:int}
     */
    public function save(Model $meeting, ?string $minutes, array $agenda, bool $minutesGiven): array
    {
        return DB::transaction(function () use ($meeting, $minutes, $agenda, $minutesGiven) {
            if ($minutesGiven) {
                $meeting->forceFill(['minutes' => $minutes])->save();
            }

            $saved = 0;
            foreach ($agenda as $row) {
                // Scoped through the relation, so an id belonging to another
                // meeting — or another tenant — simply finds nothing.
                $item = $meeting->agendaItems()->whereKey($row['id'] ?? 0)->first();
                if (! $item) {
                    continue;
                }

                $changes = [];
                foreach (['discussion', 'decision'] as $field) {
                    // By KEY, not by truthiness: clearing a note back to empty
                    // is an edit the writer meant, and `?? null` would have
                    // silently ignored it.
                    if (array_key_exists($field, $row)) {
                        $changes[$field] = $row[$field];
                    }
                }

                if ($changes) {
                    $item->forceFill($changes)->save();
                    $saved++;
                }
            }

            return ['agenda_saved' => $saved];
        });
    }
}
