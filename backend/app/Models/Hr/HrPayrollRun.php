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

    /*
    | STAGE vs STATUS — they answer different questions and both are needed.
    |
    | `status` says whether the run has been COMPUTED and whether it is locked.
    | `stage` says where it sits in the APPROVAL CHAIN. A run can be fully
    | computed (status Completed) and still be waiting on the reporting manager
    | (stage Approve) — one fact does not imply the other, and collapsing them
    | into a single column is what made "is this payroll done?" unanswerable.
    */
    public const STAGE_PRECHECK  = 'Pre-check';
    public const STAGE_INPUTS    = 'Inputs';
    public const STAGE_CALCULATE = 'Calculate';
    public const STAGE_APPROVE   = 'Approve';
    public const STAGE_DISBURSE  = 'Disburse';
    public const STAGE_PAID      = 'Paid';
    public const STAGES = [
        self::STAGE_PRECHECK, self::STAGE_INPUTS, self::STAGE_CALCULATE,
        self::STAGE_APPROVE, self::STAGE_DISBURSE, self::STAGE_PAID,
    ];

    protected $fillable = [
        'tenant_id', 'payroll_month', 'payroll_year', 'status', 'stage',
        'total_employees', 'total_gross', 'total_deductions', 'total_net', 'total_payable',
        'created_by', 'processed_by', 'processed_at',
        'approved_by', 'approved_at', 'approval_note',
        'disbursed_by', 'disbursed_at',
    ];

    protected $casts = [
        'payroll_month'    => 'integer',
        'payroll_year'     => 'integer',
        'total_employees'  => 'integer',
        'total_gross'      => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net'        => 'decimal:2',
        'total_payable'    => 'decimal:2',
        'processed_at'     => 'datetime',
        'approved_at'      => 'datetime',
        'disbursed_at'     => 'datetime',
    ];

    /** Zero-based position of the current stage, for a progress bar. */
    public function stageIndex(): int
    {
        $i = array_search($this->stage ?? self::STAGE_PRECHECK, self::STAGES, true);

        return $i === false ? 0 : $i;
    }

    /** True once the reporting manager has signed off — nothing recomputes after. */
    public function isApproved(): bool
    {
        return in_array($this->stage, [self::STAGE_DISBURSE, self::STAGE_PAID], true);
    }

    public function records()
    {
        return $this->hasMany(HrPayrollRecord::class, 'payroll_run_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disbursedBy()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /** The employees selected into this run at Pre-check. */
    public function selections()
    {
        return $this->hasMany(HrPayrollRunEmployee::class, 'payroll_run_id');
    }

    public function adjustments()
    {
        return $this->hasMany(HrPayrollAdjustment::class, 'payroll_run_id');
    }
}
