<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One weekday of a shift.
 *
 * This row is BOTH the timing and the weekly off: a day is off when `is_week_off`
 * is set. `week_numbers` narrows that to particular weeks of the month, which is
 * how an alternate-Saturday pattern is expressed without a second table.
 *
 * `day_of_week` matches Carbon::dayOfWeek (0 = Sunday), so no mapping is needed
 * anywhere the two are compared.
 */
class HrShiftTiming extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_shift_timings';

    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected $fillable = [
        'tenant_id', 'shift_id', 'day_of_week', 'start_time', 'end_time',
        'is_week_off', 'week_numbers',
    ];

    protected $casts = [
        'day_of_week'  => 'integer',
        'is_week_off'  => 'boolean',
        'week_numbers' => 'array',
    ];

    /**
     * Whether this weekday is off in the given week-of-month.
     *
     * An empty `week_numbers` means every week — the common case. A populated list
     * means only those weeks, so "2nd and 4th Saturday" is [2,4].
     */
    public function isOffInWeek(int $weekOfMonth): bool
    {
        if (! $this->is_week_off) {
            return false;
        }
        $weeks = $this->week_numbers ?: [];

        return $weeks === [] || in_array($weekOfMonth, array_map('intval', $weeks), true);
    }

    public function dayName(): string
    {
        return self::DAYS[$this->day_of_week] ?? (string) $this->day_of_week;
    }
}
