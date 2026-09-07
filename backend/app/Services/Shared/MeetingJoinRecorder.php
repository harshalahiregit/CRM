<?php

namespace App\Services\Shared;

use App\Models\User;
use App\Support\UserAgentInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance for a meeting held anywhere.
 *
 * {@see MeetingPresence} watches the call and is the better record — it knows
 * who arrived, when they left, and how long they stayed. It only works for a
 * meeting held INSIDE the CRM, because that is the only call we can see.
 *
 * Most meetings are not. An administrator schedules one on Google Meet, opens
 * it, talks for an hour and closes it, and nothing here observed any of it. The
 * register stayed empty and somebody had to reconstruct it from memory
 * afterwards, which is exactly the thing the register exists to avoid.
 *
 * What we CAN see, on every platform, is somebody pressing Join in the CRM.
 * That is not proof they stayed — a person can open the link and wander off —
 * so it is recorded as what it is: they opened this meeting, at this time, from
 * this device. It is evidence, honestly labelled, and it is available for Zoom,
 * Teams, Google Meet and anything else somebody pastes in.
 *
 * The three sources rank: an observed call beats a join click, and a join click
 * beats nothing. A tick made by hand always wins, because a person looking at
 * the meeting knows something the system does not.
 */
class MeetingJoinRecorder
{
    public const SOURCE_LINK = 'link';

    public const SOURCE_CALL = 'call';

    public const SOURCE_MANUAL = 'manual';

    /**
     * Record that this person opened the meeting, and hand back the link.
     *
     * @param  Model  $meeting  a KickoffMeeting or PurchaseKickoffMeeting
     * @param  User|Model  $actor  whoever pressed Join — a staff User, or a vendor identity
     * @return array{link:?string, recorded:bool, attendee_id:?int}
     */
    public function record(Model $meeting, $actor, Request $request): array
    {
        $link = $meeting->meeting_link;
        $roster = method_exists($meeting, 'attendees') ? 'attendees' : 'participants';

        $row = $this->rowFor($meeting, $roster, $actor);
        if (! $row) {
            // Nobody on the roster matches, and we will not invent one: a join
            // click carries no identity beyond the account that made it, and a
            // guest row built from a login would name the wrong person.
            return ['link' => $link, 'recorded' => false, 'attendee_id' => null];
        }

        $now = Carbon::now();
        $ua = UserAgentInfo::parse($request->userAgent());

        $changes = [
            'attended' => true,
            'joined_at' => $row->joined_at ?: $now,
        ];

        /*
         * A join click never overwrites what the call itself saw.
         *
         * Somebody who was watched arriving and leaving has a better record than
         * "they pressed a button". Pressing Join again afterwards must not
         * downgrade that to a click, or reopening the link from an e-mail would
         * quietly erase how long they were actually there.
         */
        if ($row->attendance_source !== self::SOURCE_CALL) {
            $changes['attendance_source'] = self::SOURCE_LINK;
            $changes['attendance_status'] = $row->attendance_status ?: 'Online';

            // Only the shared engine's roster carries a remark column; Purchase's
            // does not. Checked rather than assumed, so this stays one service
            // over two tables that were built separately and are not identical.
            if (Schema::hasColumn($row->getTable(), 'remark')) {
                $changes['remark'] = $this->stamp($now, $ua, $request->ip());
            }
        }

        $row->forceFill(array_filter($changes, fn ($v) => $v !== null))->save();

        return ['link' => $link, 'recorded' => true, 'attendee_id' => (int) $row->id];
    }

    /**
     * The roster row belonging to whoever pressed Join.
     *
     * By account first, then by address. Never by display name: the name on a
     * login and the name somebody typed onto a roster are different strings far
     * more often than not, and a wrong tick on an attendance record is worse
     * than an absent one.
     */
    private function rowFor(Model $meeting, string $roster, $actor)
    {
        $rows = $meeting->{$roster}()->get();

        $byUser = $rows->first(fn ($r) => $r->user_id && (int) $r->user_id === (int) ($actor->id ?? 0));
        if ($byUser) {
            return $byUser;
        }

        $email = $actor->email ?? null;

        return $email
            ? $rows->first(fn ($r) => $r->email && strcasecmp((string) $r->email, (string) $email) === 0)
            : null;
    }

    /**
     * What was known at the moment they joined.
     *
     * Kept on the roster row rather than only in the audit log, because the
     * person reading the register is asking "how do we know?" right there — and
     * an answer they have to go and look for somewhere else does not get read.
     */
    private function stamp(Carbon $at, array $ua, ?string $ip): string
    {
        return sprintf(
            'Opened the meeting %s from %s on %s (%s)',
            $at->format('d M Y, H:i'),
            $ua['device'],
            $ua['browser'],
            $ip ?: 'unknown address',
        );
    }
}
