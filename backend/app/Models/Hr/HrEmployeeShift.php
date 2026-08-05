<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's shift assignment — and, once superseded, their shift history.
 *
 * There is no separate history table on purpose. A row with `effective_to` null is
 * the current assignment; setting `effective_to` is what makes it history. Two
 * tables would hold identical columns and drift the first time one gained a field.
 */
class HrEmployeeShift extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_employee_shifts';

    protected $fillable = [
        'tenant_id', 'employee_id', 'shift_id', 'rotation_id',
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

    public function shift()
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }

    public function rotation()
    {
        return $this->belongsTo(HrShiftRotation::class, 'rotation_id');
    }

    public function isCurrent(): bool
    {
        return $this->effective_to === null;
    }
}
