<?php

namespace App\Services\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who was actually in the call, and for how long.
 *
 * Attendance used to be a tick somebody made afterwards from memory. The live
 * room improved on that by ticking automatically — but only for a person whose
 * name in the call matched a name on the roster character for character, which
 * is almost nobody: people type their own name into Jitsi's prejoin box, guests
 * type a first name, and the chair is often not on the roster at all. Everyone
 * else was watched arriving on screen and then quietly dropped. The meeting
 * ended with an empty attendance list and a chair who had seen it fill up.
 *
 * ── Snapshots, not events ───────────────────────────────────────────────
 * The room reports WHO IS IN THE CALL RIGHT NOW, every few seconds, rather than
 * "X joined" / "Y left" one event at a time. Events look simpler and are worse
 * here: one missed "left" leaves somebody in the call for ever, a refresh
 * replays arrivals, and every browser in the room sees every other person
 * arrive, so three participants means three reports of the same arrival.
 * Reconciling a snapshot is idempotent by construction — the same snapshot
 * applied twice is the same result — and it repairs itself after a dropped
 * request, a reload or a crash.
 *
 * ── left_at is "last seen" ──────────────────────────────────────────────
 * Every heartbeat moves left_at forward for whoever is still present. So while
 * someone is in the call it reads as the current time, and the moment they stop
 * appearing in snapshots it stands still at the last moment we saw them — which
 * is when they left, to within one heartbeat. Nothing has to be closed off, and
 * a browser that dies mid-call leaves an honest record instead of a person who
 * apparently stayed for eleven hours.
 *
 * The time between heartbeats is added to seconds_in_call, so a rejoin adds to
 * the same total rather than starting again, and joined_at keeps meaning the
 * first arrival. The delta is capped: a laptop shut mid-meeting stops reporting
 * and its owner should not be credited with the rest of the afternoon.
 */
class MeetingPresence
{
    /**
     * Longest gap between two heartbeats that still counts as continuous
     * presence. The room beats every 20s; anything beyond this was a sleeping
     * tab, a lost connection or a closed laptop, and the time is not credited.
     */
    private const MAX_GAP_SECONDS = 150;

    /**
     * Reconcile the room against the roster.
     *
     * @param  Model  $meeting  a KickoffMeeting or PurchaseKickoffMeeting
     * @param  array  $inCall   [['key' => string, 'name' => ?string, 'self' => bool], ...]
     * @param  bool  $ended  the call finished (the room was closed or left)
     * @return array{present:int, guests_added:int, actual_start_at:?string, actual_end_at:?string, seconds:int}
     */
    public function reconcile(Model $meeting, array $inCall, bool $ended, ?User $actor = null): array
    {
        return DB::transaction(function () use ($meeting, $inCall, $ended, $actor) {
            // The ordinary machine clock, UTC, like every other timestamp the
            // system stamps rather than accepts from a form. The booked slot is
            // a wall clock somebody typed and is cast accordingly; these are
            // instants, and treating them as wall clocks would move every one
            // of them by the tenant's offset.
            $now = Carbon::now();
            $roster = $this->rosterRelation($meeting);
            $existing = $meeting->{$roster}()->get();
            $guests = 0;

            foreach ($this->uniqueByKey($inCall) as $person) {
                $row = $this->match($existing, $person, $actor);

                if (! $row) {
                    // Somebody who was not on the roster turned up. They were in
                    // the meeting, so they belong in its record — dropping them
                    // is how the attendance list came out empty.
                    $row = $meeting->{$roster}()->create([
                        'tenant_id' => $meeting->tenant_id,
                        'name' => $person['name'] ?: 'Guest',
                        'side' => 'external',
                        'is_guest' => true,
                    ]);
                    $existing->push($row);
                    $guests++;
                }

                $this->markPresent($row, $person, $now, $actor);
            }

            // Anyone who has stopped appearing needs no closing write: left_at
            // already stands at the last heartbeat that saw them.

            $meeting->refresh();
            $this->stampMeeting($meeting, $ended, $now);

            $fresh = $meeting->{$roster}()->get();

            return [
                'present' => $fresh->whereNotNull('joined_at')->count(),
                'guests_added' => $guests,
                'actual_start_at' => optional($meeting->actual_start_at)->toDateTimeString(),
                'actual_end_at' => optional($meeting->actual_end_at)->toDateTimeString(),
                'held_minutes' => $meeting->held_minutes,
                'timing_state' => $meeting->timing_state,
                // The roster as it now stands, so the room can redraw the
                // attendance list from the reply instead of re-fetching the
                // whole meeting every twenty seconds.
                'attendees' => $fresh->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'organisation' => $a->organisation,
                    'attended' => (bool) $a->attended,
                    'attendance_status' => $a->attendance_status,
                    'joined_at' => optional($a->joined_at)->toDateTimeString(),
                    'left_at' => optional($a->left_at)->toDateTimeString(),
                    'seconds_in_call' => (int) $a->seconds_in_call,
                    'is_guest' => (bool) $a->is_guest,
                    'attendance_source' => $a->attendance_source,
                ])->values(),
            ];
        });
    }

    /* ── matching ────────────────────────────────────────────────────── */

    /**
     * Find this person's roster row.
     *
     * Three ways in, most reliable first. The call's own participant id is
     * exact and survives a name change mid-meeting. The signed-in user is next:
     * the chair is whoever opened the room, and tying them by account rather
     * than by display name is why the organiser now gets marked at all — they
     * were the one person guaranteed to be missed before, because Jitsi shows
     * them under whatever name their browser remembered. Name is last, and only
     * against a row not already claimed by somebody else in this call.
     */
    private function match($existing, array $person, ?User $actor)
    {
        if ($person['key'] !== '') {
            $byKey = $existing->firstWhere('participant_key', $person['key']);
            if ($byKey) {
                return $byKey;
            }
        }

        if (! empty($person['self']) && $actor) {
            $byUser = $existing->first(fn ($a) => (int) $a->user_id === (int) $actor->id
                || ($actor->email && strcasecmp((string) $a->email, (string) $actor->email) === 0));
            if ($byUser) {
                return $byUser;
            }
        }

        $name = $this->norm($person['name']);
        if ($name === '') {
            return null;
        }

        $free = $existing->filter(fn ($a) => ! $a->participant_key || $a->participant_key === $person['key']);

        $exact = $free->first(fn ($a) => $this->norm($a->name) === $name);
        if ($exact) {
            return $exact;
        }

        // Nobody types their roster name into a video call. "Sam" arrives for
        // "Sam Patel", "bale" for "Vendor Bale", and treating those as strangers
        // is how one person ends up on the roster twice. So a partial name is
        // accepted — but ONLY when it can mean exactly one person here. Two
        // Sams on the roster and this gives up, because a wrong tick on an
        // attendance record is worse than an untied guest row somebody can see
        // and fix.
        $candidates = $free->filter(fn ($a) => $this->overlaps($this->norm($a->name), $name));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /** Do these two names share a whole word, one being a subset of the other? */
    private function overlaps(string $rosterName, string $callName): bool
    {
        if ($rosterName === '' || $callName === '') {
            return false;
        }

        $roster = array_filter(explode(' ', $rosterName));
        $call = array_filter(explode(' ', $callName));

        // Every word of the shorter name appears in the longer one. Word-wise,
        // not substring: "ali" must not match "Khalid".
        $shorter = count($call) <= count($roster) ? $call : $roster;
        $longer = $shorter === $call ? $roster : $call;

        return $shorter !== [] && ! array_diff($shorter, $longer);
    }

    private function markPresent(Model $row, array $person, Carbon $now, ?User $actor): void
    {
        $changes = [
            'attended' => true,
            // How they attended, not merely that they did — they were on the
            // call, they were not in the room. Both engines know the word.
            'attendance_status' => 'Online',
            // Seen in the call — the strongest of the three sources, and the one
            // a later Join click must not overwrite. See MeetingJoinRecorder.
            'attendance_source' => MeetingJoinRecorder::SOURCE_CALL,
            'participant_key' => $person['key'] ?: $row->participant_key,
        ];

        if (! $row->joined_at) {
            $changes['joined_at'] = $now;
        } else {
            $gap = $row->left_at ? Carbon::parse($row->left_at)->diffInSeconds($now, false) : 0;
            if ($gap > 0) {
                $changes['seconds_in_call'] = (int) $row->seconds_in_call + min($gap, self::MAX_GAP_SECONDS);
            }
        }

        // "Last seen", moved forward on every heartbeat — see the class note.
        $changes['left_at'] = $now;

        // A guest row that turns out to be the signed-in chair stops being a
        // guest and gains their account, so the record names a person.
        if (! empty($person['self']) && $actor && ! $row->user_id) {
            $changes['user_id'] = $actor->id;
            $changes['is_guest'] = false;
            if (! $row->email && $actor->email) {
                $changes['email'] = $actor->email;
            }
            if ($actor->name) {
                $changes['name'] = $actor->name;
            }
        }

        $row->forceFill($changes)->save();
    }

    /* ── the meeting's own clock ─────────────────────────────────────── */

    /**
     * When the meeting really ran.
     *
     * The end is the last moment anybody was seen, not the moment the report
     * arrived: the chair pressing Leave is when the record should stop, and by
     * then everyone's last-seen is already written. A later heartbeat with
     * people in it clears the end again — a call picked back up is one meeting
     * that resumed, not a finished one.
     */
    private function stampMeeting(Model $meeting, bool $ended, Carbon $now): void
    {
        $roster = $this->rosterRelation($meeting);
        $changes = [];

        $firstJoin = $meeting->{$roster}()->whereNotNull('joined_at')->min('joined_at');
        if ($firstJoin && ! $meeting->actual_start_at) {
            $changes['actual_start_at'] = $firstJoin;
        }

        // Heard from, just now. Moved forward on every report so a call whose
        // browser dies without hanging up stops looking live a few minutes
        // later instead of for ever — see MeetingTiming::STALE_AFTER_MINUTES.
        $changes['presence_seen_at'] = $now;

        if ($ended) {
            $lastSeen = $meeting->{$roster}()->whereNotNull('left_at')->max('left_at');
            $changes['actual_end_at'] = $lastSeen ?: $now;
        } elseif ($meeting->actual_end_at) {
            $changes['actual_end_at'] = null;
        }

        if ($changes) {
            // Quietly: presence is a stream of small writes and must not fire
            // the meeting's update side effects (re-notifying the roster, and
            // so on) every twenty seconds while people are talking.
            $meeting->forceFill($changes)->saveQuietly();
        }
    }

    /* ── helpers ─────────────────────────────────────────────────────── */

    /** The shared engine calls them attendees, Purchase calls them participants. */
    private function rosterRelation(Model $meeting): string
    {
        return method_exists($meeting, 'attendees') ? 'attendees' : 'participants';
    }

    /**
     * One entry per person.
     *
     * Everyone in a call sees everyone else, so the same arrival can be reported
     * more than once within a single snapshot; and a browser with no id for
     * somebody falls back to their name, which two guests can share.
     */
    private function uniqueByKey(array $inCall): array
    {
        $out = [];
        foreach ($inCall as $p) {
            $key = trim((string) ($p['key'] ?? ''));
            $name = trim((string) ($p['name'] ?? ''));
            if ($key === '' && $name === '') {
                continue;
            }
            $out[$key !== '' ? 'k:'.$key : 'n:'.$this->norm($name)] = [
                'key' => $key,
                'name' => $name,
                'self' => (bool) ($p['self'] ?? false),
            ];
        }

        return array_values($out);
    }

    private function norm(?string $s): string
    {
        return preg_replace('/\s+/', ' ', trim(mb_strtolower((string) $s)));
    }
}
