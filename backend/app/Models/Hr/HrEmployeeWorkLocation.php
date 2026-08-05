<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an employee sits — and, once superseded, where they used to sit.
 *
 * Same effective-dated shape as HrEmployeeShift: `effective_to` null is current,
 * set is history. One table, one truth.
 */
class HrEmployeeWorkLocation extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_employee_work_locations';

    protected $fillable = [
        'tenant_id', 'employee_id', 'branch_id', 'office_id', 'floor_id', 'seat_no',
        'effective_from', 'effective_to', 'reason', 'assigned_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function branch()
    {
        return $this->belongsTo(HrBranch::class, 'branch_id');
    }

    public function office()
    {
        return $this->belongsTo(HrOffice::class, 'office_id');
    }

    public function floor()
    {
        return $this->belongsTo(HrFloor::class, 'floor_id');
    }
}
