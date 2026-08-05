<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A shift: a name, its per-weekday timings, and the weekly off pattern that comes
 * with them. The timings ARE the weekly off definition — see HrShiftTiming.
 */
class HrShift extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_shifts';

    public const FIXED = 'Fixed';
    public const ROTATIONAL = 'Rotational';
    public const FLEXIBLE = 'Flexible';
    public const TYPES = [self::FIXED, self::ROTATIONAL, self::FLEXIBLE];

    protected $fillable = [
        'tenant_id', 'name', 'code', 'shift_type', 'is_night_shift',
        'grace_in_minutes', 'grace_out_minutes', 'break_minutes',
        'full_day_hours', 'half_day_hours', 'description', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_night_shift'    => 'boolean',
        'is_active'         => 'boolean',
        'grace_in_minutes'  => 'integer',
        'grace_out_minutes' => 'integer',
        'break_minutes'     => 'integer',
        'full_day_hours'    => 'decimal:2',
        'half_day_hours'    => 'decimal:2',
    ];

    public function timings()
    {
        return $this->hasMany(HrShiftTiming::class, 'shift_id')->orderBy('day_of_week');
    }

    public function assignments()
    {
        return $this->hasMany(HrEmployeeShift::class, 'shift_id');
    }
}
