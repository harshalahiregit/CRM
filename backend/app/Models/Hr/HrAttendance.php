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
        'General' => ['09:00', '18:00', 15],
        'Morning' => ['06:00', '14:00', 10],
        'Evening' => ['14:00', '22:00', 10],
        'Night'   => ['22:00', '06:00', 10],
        'Custom'  => [null, null, 0],
    ];

    /** Standard full working day (hours) used for overtime/half-day derivation. */
    public const STANDARD_HOURS = 8.0;

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
