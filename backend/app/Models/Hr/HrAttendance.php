<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrAttendance extends Model
{
    use Auditable;

    protected $table = 'hr_attendance';

    /** Supported attendance statuses. */
    public const STATUSES = ['Present', 'Absent', 'Late', 'Half Day', 'Leave', 'Holiday', 'Weekend', 'Work From Home', 'Remote'];

    /** Shift presets: [start, end, grace-minutes]. "Custom" carries its own times. */
    public const SHIFTS = [
        // Seed values only. The working day is configured per workspace under
        // HR Settings -> Working day; AttendanceService reads those and falls
        // back here when a setting is unreadable. Keep this row in step with
        // HrSetting's defaults so a fresh workspace behaves the same either way.
        'General' => ['09:30', '18:30', 15],
        'Morning' => ['06:00', '14:00', 10],
        'Evening' => ['14:00', '22:00', 10],
        'Night'   => ['22:00', '06:00', 10],
        'Custom'  => [null, null, 0],
    ];

    /** Standard full working day (hours) used for overtime/half-day derivation. */
    // 09:30-18:30 is a nine-hour span. Overtime accrues past this, and it is a
    // fallback only — HR Settings -> Working day -> 'Full day' is the authority
    // and is what a workspace should change.
    //
    // OPEN: whether a lunch break should come off before overtime starts. Nine
    // hours is the stated span, not necessarily the payable day.
    public const STANDARD_HOURS = 9.0;

    protected $fillable = [
        'tenant_id', 'employee_id', 'date',
        'shift', 'shift_start', 'shift_end', 'grace_period',
        'check_in', 'check_out', 'break_start', 'break_end',
        'working_hours', 'overtime_hours', 'status', 'remarks',
    ];

    protected $casts = [
        'date'           => 'date',
        'check_in'       => 'datetime',
        'check_out'      => 'datetime',
        'break_start'    => 'datetime',
        'break_end'      => 'datetime',
        'working_hours'  => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'grace_period'   => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
