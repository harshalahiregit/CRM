<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to fix a wrong or missing punch.
 *
 * The requested times live here, not on hr_attendance: a request is a claim
 * about a day, and only an approval turns it into an edit.
 */
class HrAttendanceCorrection extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_attendance_corrections';

    public const PENDING  = 'pending';
    public const ON_HOLD  = 'on_hold';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public const ALL = [self::PENDING, self::ON_HOLD, self::APPROVED, self::REJECTED];

    protected $fillable = [
        'tenant_id', 'employee_id', 'attendance_id', 'attendance_date',
        'requested_check_in', 'requested_check_out', 'requested_break_start', 'requested_break_end',
        'reason', 'status', 'held_from', 'admin_remarks', 'decided_by', 'decided_at', 'applied',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'decided_at'      => 'datetime',
        'applied'         => 'boolean',
    ];

    protected $appends = ['is_decided'];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function attendance()
    {
        return $this->belongsTo(HrAttendance::class, 'attendance_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function messages()
    {
        return $this->morphMany(HrRequestMessage::class, 'subject');
    }

    public function isOnHold(): bool
    {
        return $this->status === self::ON_HOLD;
    }

    public function getIsDecidedAttribute(): bool
    {
        return in_array($this->status, [self::APPROVED, self::REJECTED], true);
    }

    /** The times actually asked for, skipping the ones left alone. */
    public function requestedTimes(): array
    {
        return array_filter([
            'check_in'    => $this->requested_check_in,
            'check_out'   => $this->requested_check_out,
            'break_start' => $this->requested_break_start,
            'break_end'   => $this->requested_break_end,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
