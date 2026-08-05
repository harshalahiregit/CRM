<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One leg of a rotation: which shift, and for how many days. */
class HrShiftRotationStep extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_shift_rotation_steps';

    protected $fillable = ['tenant_id', 'rotation_id', 'shift_id', 'sequence', 'duration_days'];

    protected $casts = ['sequence' => 'integer', 'duration_days' => 'integer'];

    public function shift()
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }

    public function rotation()
    {
        return $this->belongsTo(HrShiftRotation::class, 'rotation_id');
    }
}
