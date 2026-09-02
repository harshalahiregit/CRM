<?php

namespace App\Services\Hr;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\ReimbursementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance for a month, per employee and per department, with the money that
 * changes what somebody is paid.
 *
 * Built to answer one question: what does this person get this month. That means
 * days and hours from hr_attendance, plus two figures nobody could see together
 * before — expense claims approved in the period (paid out) and advances still
 * outstanding (to recover). Payroll was being decided with those in three
 * different screens.
 *
 * PAYABLE DAYS IS DELIBERATELY CONSERVATIVE. It counts only the statuses whose
 * value is not a policy question: a full day worked is one day, a half day is
 * half. Leave is reported SEPARATELY and never folded in, because whether a
 * given leave is paid is a company's own rule and this service does not know it.
 * Quietly counting leave as paid would produce a number that looks authoritative
 * and is wrong for half its readers.
 */
class AttendanceReportService
{
    /** Statuses worth a full day, whatever they are called. */
    private const FULL_DAY = ['Present', 'Late', 'Work From Home', 'Remote'];

    private const HALF_DAY = ['Half Day'];

    /** Not worked and not payable by any reading. */
    private const ABSENT = ['Absent'];

    /** Needs the company's leave policy — reported, never folded into pay. */
    private const POLICY = ['Leave'];

    /** Non-working days: neither earned nor lost. */
    private const NON_WORKING = ['Holiday', 'Weekend'];

    /**
     * One row per employee for a month.
     *
     * @param  string  $month  YYYY-MM
     */
    public function monthly(int $tenantId, string $month, ?string $department = null, ?int $employeeId = null): array
    {
        [$from, $to] = $this->bounds($month);

        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->when($department, fn ($q, $d) => $q->where('department', $d))
            ->when($employeeId, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department', 'designation', 'status']);

        if ($employees->isEmpty()) {
            return ['month' => $month, 'rows' => [], 'totals' => $this->emptyTotals()];
        }

        $ids = $employees->pluck('id')->all();

        $attendance   = $this->attendanceByEmployee($tenantId, $ids, $from, $to);
        $reimbursed   = $this->reimbursedByEmployee($tenantId, $ids, $from, $to);
        $outstanding  = $this->outstandingByEmployee($tenantId, $ids);

        $rows = $employees->map(function ($e) use ($attendance, $reimbursed, $outstanding) {
            $a = $attendance[$e->id] ?? [];

            $full = $this->sumOf($a, self::FULL_DAY);
            $half = $this->sumOf($a, self::HALF_DAY);

            return [
                'employee_id'    => $e->id,
                'name'           => $e->name,
                'employee_code'  => $e->employee_code,
                'department'     => $e->department,
                'designation'    => $e->designation,
                'employment'     => $e->status,

                'days_recorded'  => (int) array_sum(array_column($a, 'days')),
                'present_days'   => $full,
                'half_days'      => $half,
                'absent_days'    => $this->sumOf($a, self::ABSENT),
                'leave_days'     => $this->sumOf($a, self::POLICY),
                'non_working'    => $this->sumOf($a, self::NON_WORKING),
                'late_days'      => $this->sumOf($a, ['Late']),

                // Conservative on purpose — see the class note.
                'payable_days'   => round($full + ($half * 0.5), 2),

                'working_hours'  => round((float) array_sum(array_column($a, 'hours')), 2),
                'overtime_hours' => round((float) array_sum(array_column($a, 'overtime')), 2),

                'reimbursements_approved' => round((float) ($reimbursed[$e->id] ?? 0), 2),
                'advance_outstanding'     => round((float) ($outstanding[$e->id] ?? 0), 2),
            ];
        })->values()->all();

        return ['month' => $month, 'rows' => $rows, 'totals' => $this->totals($rows)];
    }

    /**
     * One employee, day by day — the view somebody opens when a monthly figure
     * looks wrong and they need to see which day caused it.
     */
    public function forEmployee(int $tenantId, int $employeeId, string $month): array
    {
        [$from, $to] = $this->bounds($month);

        $employee = HrEmployee::where('tenant_id', $tenantId)->findOrFail($employeeId);

        $days = HrAttendance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            // whereDate, not whereBetween: the `date` cast persists midnight, so
            // '2026-03-31 00:00:00' falls OUTSIDE between(…, '2026-03-31') and the
            // last day of every month silently disappeared from the report.
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->get(['id', 'date', 'shift', 'check_in', 'check_out', 'working_hours', 'overtime_hours', 'status', 'remarks']);

        $summary = $this->monthly($tenantId, $month, null, $employeeId);

        return [
            'month'    => $month,
            'employee' => $employee->only(['id', 'name', 'employee_code', 'department', 'designation']),
            'summary'  => $summary['rows'][0] ?? null,
            'days'     => $days,
        ];
    }

    /** Rolled up by department, for the month. */
    public function byDepartment(int $tenantId, string $month): array
    {
        $monthly = $this->monthly($tenantId, $month);

        $grouped = collect($monthly['rows'])
            ->groupBy(fn ($r) => $r['department'] ?: 'Unassigned')
            ->map(fn ($rows, $dept) => array_merge(
                ['department' => $dept, 'headcount' => $rows->count()],
                $this->totals($rows->all())
            ))
            ->sortBy('department')
            ->values()
            ->all();

        return ['month' => $month, 'rows' => $grouped, 'totals' => $monthly['totals']];
    }

    /* ── internals ───────────────────────────────────────────────────── */

    /**
     * Attendance aggregated in SQL, one row per employee per status.
     *
     * Grouped in the database rather than pulled row by row: a month for 200
     * people is 6,000 rows, and building this in PHP would read every one of
     * them to produce twenty numbers.
     */
    private function attendanceByEmployee(int $tenantId, array $ids, string $from, string $to): array
    {
        $rows = HrAttendance::where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->groupBy('employee_id', 'status')
            ->select(
                'employee_id',
                'status',
                DB::raw('COUNT(*) as days'),
                DB::raw('COALESCE(SUM(working_hours), 0) as hours'),
                DB::raw('COALESCE(SUM(overtime_hours), 0) as overtime')
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->employee_id][] = [
                'status'   => (string) $r->status,
                'days'     => (int) $r->days,
                'hours'    => (float) $r->hours,
                'overtime' => (float) $r->overtime,
            ];
        }

        return $out;
    }

    /**
     * Claims APPROVED with a decision inside the period.
     *
     * By decided_at, not expense_date: payroll pays what was signed off this
     * month, and a claim for a March dinner approved in May is May's money.
     */
    private function reimbursedByEmployee(int $tenantId, array $ids, string $from, string $to): array
    {
        return HrReimbursement::where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->where('status', ReimbursementStatus::APPROVED)
            ->whereBetween('decided_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->groupBy('employee_id')
            // Aliased rather than plucked off a raw expression: pluck keys on the
            // column NAME, and a raw expression's name is the expression, so that
            // form returns nothing at all instead of failing loudly.
            ->selectRaw('employee_id, COALESCE(SUM(amount_approved), 0) as total')
            ->pluck('total', 'employee_id')
            ->all();
    }

    /**
     * Advances already handed over and not yet settled — as of now, not as of
     * the month. What is owed is owed today; asking "what was outstanding in
     * March" is a different report nobody has asked for.
     */
    private function outstandingByEmployee(int $tenantId, array $ids): array
    {
        return HrAdvance::where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->whereIn('status', [AdvanceStage::DISBURSED, AdvanceStage::SETTLEMENT_SUBMITTED])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COALESCE(SUM(disbursed_amount), 0) as total')
            ->pluck('total', 'employee_id')
            ->all();
    }

    private function sumOf(array $rows, array $statuses): int
    {
        $n = 0;
        foreach ($rows as $r) {
            if (in_array($r['status'], $statuses, true)) {
                $n += $r['days'];
            }
        }

        return $n;
    }

    private function totals(array $rows): array
    {
        $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);

        return [
            'employees'      => count($rows),
            'present_days'   => (int) $sum('present_days'),
            'half_days'      => (int) $sum('half_days'),
            'absent_days'    => (int) $sum('absent_days'),
            'leave_days'     => (int) $sum('leave_days'),
            'late_days'      => (int) $sum('late_days'),
            'payable_days'   => $sum('payable_days'),
            'working_hours'  => $sum('working_hours'),
            'overtime_hours' => $sum('overtime_hours'),
            'reimbursements_approved' => $sum('reimbursements_approved'),
            'advance_outstanding'     => $sum('advance_outstanding'),
        ];
    }

    private function emptyTotals(): array
    {
        return $this->totals([]);
    }

    /**
     * The first and last day of a YYYY-MM month.
     *
     * Anything unparseable falls back to the current month rather than throwing:
     * a report is not worth a 500, and the response says which month it used.
     */
    private function bounds(string $month): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
        } catch (\Throwable) {
            $start = CarbonImmutable::now()->startOfMonth();
        }

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
