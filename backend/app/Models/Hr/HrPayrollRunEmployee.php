<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * One employee's membership of one payroll run.
 *
 * The presence of a row IS the selection — there is no `included` flag, because
 * a boolean would let "not selected" and "selected then deselected" look
 * different in the database while meaning the same thing to payroll.
 *
 * `blocked_reason` is set by the pre-check for somebody who cannot be paid yet
 * (no bank account, no PAN, no active salary). Those rows are still written, on
 * purpose: silently omitting an employee is how a person misses a salary and
 * nobody notices until they ask. A blocked row is visible, explains itself, and
 * is excluded from the calculation.
 */
class HrPayrollRunEmployee extends Model
{
    protected $table = 'hr_payroll_run_employees';

    protected $fillable = [
        'tenant_id', 'payroll_run_id', 'employee_id', 'blocked_reason',
    ];

    /** Selectable = nothing is stopping this person from being paid. */
    public function isPayable(): bool
    {
        return $this->blocked_reason === null;
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function run()
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }
}
