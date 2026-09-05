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
        // Punch evidence — where it happened, the photo, and the address it came
        // from. Sent by the app on every clock in and out.
        'check_in_latitude', 'check_in_longitude', 'check_in_address',
        'check_in_selfie', 'check_in_ip',
        'check_out_latitude', 'check_out_longitude', 'check_out_address',
        'check_out_selfie', 'check_out_ip',
        'working_hours', 'overtime_hours', 'status', 'remarks',
    ];

    /**
     * The punch selfies travel as signed links, never as storage paths.
     *
     * Appended rather than mapped in a service because the register, the exports
     * and anything else that serialises this model all need them, and the one
     * that did not have them showed a blank column while the file sat on disk.
     * The raw paths are hidden: they are of no use to a caller and a path is an
     * invitation to construct a direct link that bypasses the signature.
     */
    protected $appends = ['check_in_selfie_url', 'check_out_selfie_url'];

    protected $hidden = ['check_in_selfie', 'check_out_selfie'];

    public function getCheckInSelfieUrlAttribute(): ?string
    {
        return $this->signedSelfie('check_in_selfie', 'in');
    }

    public function getCheckOutSelfieUrlAttribute(): ?string
    {
        return $this->signedSelfie('check_out_selfie', 'out');
    }

    /** Good for an hour — long enough to review a register, short enough to expire. */
    private function signedSelfie(string $column, string $which): ?string
    {
        if (! $this->{$column} || ! $this->id) {
            return null;
        }

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'hr.attendance.selfie',
            now()->addHour(),
            ['attendance' => $this->id, 'which' => $which],
        );
    }

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
