<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's salary assignment (Payroll Phase 3) — a frozen snapshot of a
 * Salary Structure at a point in time. Historical rows are immutable in spirit:
 * a revision archives the prior row rather than editing it. Never hard-deleted.
 */
class HrEmployeeSalary extends Model
{
    use Auditable;

    protected $table = 'hr_employee_salaries';

    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    protected $fillable = [
        'tenant_id', 'employee_id', 'salary_structure_id',
        'effective_from', 'effective_to', 'revision_no', 'reason', 'assigned_by',
        'annual_ctc', 'monthly_ctc', 'gross_salary', 'total_benefits', 'total_deductions', 'net_salary',
        'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'effective_from'   => 'date',
        'effective_to'     => 'date',
        'annual_ctc'       => 'decimal:2',
        'monthly_ctc'      => 'decimal:2',
        'gross_salary'     => 'decimal:2',
        'total_benefits'   => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary'       => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function structure()
    {
        return $this->belongsTo(HrSalaryStructure::class, 'salary_structure_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
