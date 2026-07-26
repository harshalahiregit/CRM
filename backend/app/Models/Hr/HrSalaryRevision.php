<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable salary-revision ledger row (Enterprise Salary Engine). Written once
 * per (re)assignment with the before/after CTC snapshot and the reason. Never edited
 * or hard-deleted — the source for the Employee Profile revision history and the
 * Revision History report.
 */
class HrSalaryRevision extends Model
{
    protected $table = 'hr_salary_revisions';

    protected $fillable = [
        'tenant_id', 'employee_id', 'employee_salary_id', 'from_structure_id', 'to_structure_id',
        'revision_no', 'effective_from', 'reason',
        'previous_monthly_ctc', 'previous_annual_ctc', 'previous_net_salary',
        'new_monthly_ctc', 'new_annual_ctc', 'new_gross_salary', 'new_employer_contribution',
        'new_total_deduction', 'new_net_salary', 'changed_by',
    ];

    protected $casts = [
        'effective_from'            => 'date',
        'previous_monthly_ctc'      => 'decimal:2',
        'previous_annual_ctc'       => 'decimal:2',
        'previous_net_salary'       => 'decimal:2',
        'new_monthly_ctc'           => 'decimal:2',
        'new_annual_ctc'            => 'decimal:2',
        'new_gross_salary'          => 'decimal:2',
        'new_employer_contribution' => 'decimal:2',
        'new_total_deduction'       => 'decimal:2',
        'new_net_salary'            => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function toStructure()
    {
        return $this->belongsTo(HrSalaryStructure::class, 'to_structure_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
