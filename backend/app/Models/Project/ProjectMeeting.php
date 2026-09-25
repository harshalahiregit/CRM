<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectMeeting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'title', 'mode', 'meeting_link', 'participants',
        'planned_date', 'meeting_date', 'status', 'mom_sent', 'notes', 'created_by',
    ];

    protected $casts = [
        'planned_date' => 'datetime',
        'meeting_date' => 'datetime',
        'mom_sent'     => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project()
    {
        return $this->belongsTo(\App\Models\Project\Project::class, 'project_id');
    }

    /**
     * The names the rest of the meetings code already speaks.
     *
     * This table was built on its own, years apart from kickoff_meetings, so it
     * calls the same things different words: planned_date/meeting_date rather
     * than scheduled_at, and no reference number at all. MeetingLinkAnnouncer
     * sends the joining link for every meeting in the system, and it should not
     * have to carry a translation table for one of them.
     */
    public function getScheduledAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->meeting_date ?: $this->planned_date;
    }

    public function getMeetingNoAttribute(): string
    {
        return 'PM-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }
}
