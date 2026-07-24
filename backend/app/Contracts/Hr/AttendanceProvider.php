<?php

namespace App\Contracts\Hr;

/**
 * Attendance integration boundary for Payroll.
 *
 * HRMS never stores or computes attendance — it consumes it. Payroll depends on
 * this interface, not on any concrete source, so the external SangoeTrack app can
 * be plugged in later (SangoeTrackAttendanceProvider) WITHOUT touching payroll
 * logic: just rebind the interface. Until then PlaceholderAttendanceProvider
 * returns "not connected" with full payable days.
 */
interface AttendanceProvider
{
    /** Whether a real attendance source is wired up. */
    public function isConnected(): bool;

    /** Human-readable source name, e.g. "SangoeTrack". */
    public function source(): string;

    /**
     * Attendance reference for one employee over a period ("YYYY-MM").
     *
     * @return array{connected:bool, source:string, period:string, payable_days:float, absent_days:float, leave_days:float, message:?string}
     */
    public function forPeriod(int $employeeId, int $tenantId, string $period): array;
}
