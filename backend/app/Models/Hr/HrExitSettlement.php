<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exit Full & Final Settlement (Exit Phase 5). Created only for exits whose
 * clearance is Completed. `components` holds a FROZEN snapshot computed once at
 * generation from existing Payroll / Salary / Leave data — never recomputed and
 * never written back to payroll. Lifecycle: Pending → Generated → Reviewed →
 * Approved → Settled; Settled is immutable. Auditable trail is the settlement
 * timeline.
 */
class HrExitSettlement extends Model
{
    use Auditable;

    protected $table = 'hr_exit_settlements';

    public const PENDING = 'Pending';
    public const GENERATED = 'Generated';
    public const REVIEWED = 'Reviewed';
    public const APPROVED = 'Approved';
    public const SETTLED = 'Settled';

    protected $fillable = [
        'tenant_id', 'exit_request_id', 'clearance_id', 'employee_id',
        'status', 'settlement_month', 'components', 'gross_earnings', 'total_recoveries', 'net_settlement',
        'generated_at', 'reviewed_at', 'approved_at', 'settled_at',
        'generated_by', 'reviewed_by', 'approved_by', 'settled_by',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'components'        => 'array',
        'gross_earnings'    => 'decimal:2',
        'total_recoveries'  => 'decimal:2',
        'net_settlement'    => 'decimal:2',
        'generated_at'      => 'datetime',
        'reviewed_at'       => 'datetime',
        'approved_at'       => 'datetime',
        'settled_at'        => 'datetime',
    ];

    public function exitRequest()
    {
        return $this->belongsTo(HrExitRequest::class, 'exit_request_id');
    }

    public function clearance()
    {
        return $this->belongsTo(HrExitClearance::class, 'clearance_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
