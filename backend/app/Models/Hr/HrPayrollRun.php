<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A monthly payroll run (Payroll Phase 4). Holds the roll-up totals; the
 * per-employee frozen snapshots live in hr_payroll_records. Never hard-deleted —
 * a run is Cancelled, not removed. A Completed run is finalized and immutable.
 */
class HrPayrollRun extends Model
{
    use Auditable;

    protected $table = 'hr_payroll_runs';

    public const DRAFT = 'Draft';
    public const PROCESSING = 'Processing';
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';
    public const STATUSES = [self::DRAFT, self::PROCESSING, self::COMPLETED, self::CANCELLED];

    protected $fillable = [
        'tenant_id', 'payroll_month', 'payroll_year', 'status',
        'total_employees', 'total_gross', 'total_deductions', 'total_net',
        'created_by', 'processed_by', 'processed_at',
    ];

    protected $casts = [
        'payroll_month'    => 'integer',
        'payroll_year'     => 'integer',
        'total_employees'  => 'integer',
        'total_gross'      => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net'        => 'decimal:2',
        'processed_at'     => 'datetime',
    ];

    public function records()
    {
        return $this->hasMany(HrPayrollRecord::class, 'payroll_run_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
