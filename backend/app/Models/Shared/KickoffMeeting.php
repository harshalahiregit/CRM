<?php

namespace App\Models\Shared;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Shared\KickoffStatus as Status;
use App\Support\Shared\KickoffSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pre-onboarding (or pre-project) kickoff meeting.
 *
 * Shared, not TPV-owned: it attaches polymorphically to any allowlisted subject
 * so Shivam's Project&Task module can plug in without a second table.
 */
class KickoffMeeting extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'kickoff_meetings';

    protected $fillable = [
        'tenant_id','created_by','kickoffable_type','kickoffable_id',
        'reference','title','agenda','status',
        'scheduled_at','duration_minutes','mode','location',
        'original_scheduled_at','delay_reason',
        'mom_path','minutes','completed_at',
        'ack_token','acknowledged_at','acknowledged_by_name','acknowledged_ip',
    ];

    protected $casts = [
        'scheduled_at'          => 'datetime',
        'original_scheduled_at' => 'datetime',
        'completed_at'          => 'datetime',
        'acknowledged_at'       => 'datetime',
        'duration_minutes'      => 'integer',
    ];

    /**
     * The ack link is a bearer credential — possession lets a vendor acknowledge.
     * Never let it ride along in a list/show payload; it is disclosed only in the
     * audited publish-minutes response.
     */
    protected $hidden = ['ack_token'];

    protected $appends = ['status_label', 'is_acknowledged', 'subject'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Vendor / onboarding today; project next. */
    public function kickoffable()
    {
        return $this->morphTo();
    }

    public function attendees()
    {
        return $this->hasMany(KickoffAttendee::class, 'kickoff_meeting_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getIsAcknowledgedAttribute(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** Structured subject so a listing shows the vendor name, not a row id. */
    public function getSubjectAttribute(): ?array
    {
        if (! $this->kickoffable_type) {
            return null;
        }
        $key = KickoffSubject::keyFor($this->kickoffable_type);

        return [
            'type'  => $key,
            'id'    => $this->kickoffable_id,
            'name'  => KickoffSubject::nameOf($this->kickoffable),
            'label' => KickoffSubject::label($key),
        ];
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', Status::OPEN);
    }

    public function scopeFor($query, string $type, int $id)
    {
        return $query->where('kickoffable_type', $type)->where('kickoffable_id', $id);
    }
}
