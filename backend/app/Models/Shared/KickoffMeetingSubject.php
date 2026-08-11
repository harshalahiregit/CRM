<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Support\Shared\KickoffSubject;
use Illuminate\Database\Eloquent\Model;

/**
 * One vendor on a kickoff meeting.
 *
 * A meeting can cover several third-party vendors. The FIRST stays on
 * `kickoff_meetings.kickoffable_*` (so the vendor portal, the onboarding pointer
 * and scopeFor() keep working unchanged) and is mirrored here with
 * `is_primary = true`; the rest exist only here. Every vendor is present in this
 * table, so "who is on this meeting" is one query rather than a union.
 *
 * Acknowledgement is per row: each vendor signs off its own copy of the minutes,
 * because one shared token could not say which vendor had actually agreed.
 */
class KickoffMeetingSubject extends Model
{
    use BelongsToTenant;

    protected $table = 'kickoff_meeting_subjects';

    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id', 'subject_type', 'subject_id',
        'is_primary', 'ack_token', 'acknowledged_at', 'acknowledged_by_name', 'acknowledged_ip',
    ];

    protected $casts = [
        'is_primary'      => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    /** The vendor itself — TPV or purchase, resolved polymorphically. */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    /** Name/label for display, using the same resolver the meeting uses. */
    public function toDisplayArray(): array
    {
        $key = KickoffSubject::keyFor($this->subject_type);

        return [
            'id'              => $this->id,
            'type'            => $key,
            'subject_id'      => $this->subject_id,
            'name'            => KickoffSubject::nameOf($this->subject),
            'label'           => KickoffSubject::label($key),
            'is_primary'      => (bool) $this->is_primary,
            'acknowledged'    => (bool) $this->acknowledged_at,
            'acknowledged_at' => optional($this->acknowledged_at)->toIso8601String(),
            'acknowledged_by' => $this->acknowledged_by_name,
        ];
    }
}
