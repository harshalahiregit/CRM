<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A scheduled meeting against a lead (and, by design, anything else later).
 *
 * Deliberately polymorphic via subject_type/subject_id rather than lead-only: the
 * old CRM got appointments from a third-party module bolted onto the lead profile,
 * which is why they never worked anywhere else.
 */
class Appointment extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const MODES = ['in_person', 'phone', 'video'];
    public const STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'];

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id',
        'title', 'description', 'starts_at', 'ends_at', 'location',
        'mode', 'meeting_url', 'status', 'outcome',
        'assigned_to', 'remind_before_minutes', 'reminded_at',
        'created_by',
    ];

    protected $casts = [
        'starts_at'   => 'datetime',
        'ends_at'     => 'datetime',
        'reminded_at' => 'datetime',
    ];

    protected $appends = ['is_past'];

    /** Drives the "overdue / needs closing out" hint in the UI. */
    public function getIsPastAttribute(): bool
    {
        return $this->status === 'scheduled'
            && $this->starts_at !== null
            && $this->starts_at->isPast();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'scheduled')->where('starts_at', '>=', now());
    }
}
