<?php

namespace App\Services\Hr\Attendance;

use App\Contracts\Hr\AttendanceProvider;
use Illuminate\Support\Carbon;

/**
 * Default AttendanceProvider — a placeholder until SangoeTrack integration lands.
 *
 * Reports "not connected" and returns the full number of days in the period as
 * payable days (zero absent / leave), so payroll processing runs end-to-end
 * without any attendance-based proration. Creates no tables and holds no state.
 * Replace the binding with a SangoeTrackAttendanceProvider to go live.
 */
class PlaceholderAttendanceProvider implements AttendanceProvider
{
    public function isConnected(): bool
    {
        return false;
    }

    public function source(): string
    {
        return 'SangoeTrack';
    }

    public function forPeriod(int $employeeId, int $tenantId, string $period): array
    {
        // Full month = payable days placeholder (no proration while disconnected).
        try {
            [$year, $month] = array_map('intval', explode('-', $period));
            $payableDays = Carbon::create($year, $month, 1)->daysInMonth;
        } catch (\Throwable $e) {
            $payableDays = 30;
        }

        return [
            'connected'    => false,
            'source'       => $this->source(),
            'period'       => $period,
            'payable_days' => (float) $payableDays,
            'absent_days'  => 0.0,
            'leave_days'   => 0.0,
            'message'      => 'Attendance data not connected',
        ];
    }
}
