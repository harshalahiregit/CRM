<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A rotation plan: an ordered cycle of shifts an employee moves through. */
class HrShiftRotation extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_shift_rotations';

    protected $fillable = ['tenant_id', 'name', 'code', 'description', 'is_active', 'created_by', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function steps()
    {
        return $this->hasMany(HrShiftRotationStep::class, 'rotation_id')->orderBy('sequence');
    }

    /** Total days in one full cycle — 0 when the plan has no steps. */
    public function cycleDays(): int
    {
        return (int) $this->steps->sum('duration_days');
    }
}
