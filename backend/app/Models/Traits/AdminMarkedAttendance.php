<?php

namespace App\Models\Traits;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The admin's side of an attendance row — the typed in/out times, and who
 * typed them.
 *
 * Shared by KickoffAttendee and PurchaseKickoffParticipant. The two rosters were
 * built separately and every difference between them has cost us a defect, so
 * this half is written once.
 */
trait AdminMarkedAttendance
{
    /** The account that ticked this person. */
    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /** "Marked by X on DATE" needs the name, and every reader wants it. */
    public function getMarkedByNameAttribute(): ?string
    {
        return $this->marked_by ? ($this->markedBy?->name) : null;
    }

    /**
     * How long the admin says they were in the meeting, in whole minutes.
     *
     * Null when either end is missing — a duration computed from one time is a
     * guess, and this column exists precisely because guesses were the problem.
     */
    public function getAttendanceMinutesAttribute(): ?int
    {
        if (! $this->in_at || ! $this->out_at) {
            return null;
        }

        $from = Carbon::parse($this->in_at);
        $to = Carbon::parse($this->out_at);

        if (! $to->greaterThan($from)) {
            return null;
        }

        return (int) $from->diffInMinutes($to);
    }
}
