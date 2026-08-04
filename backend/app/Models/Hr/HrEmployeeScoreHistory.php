<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #39 — one scoring run, kept forever.
 *
 * Append-only by contract: nothing in the codebase updates or deletes a row
 * here. "Allow recalculation / do not overwrite historical scores" is only true
 * if this table is never rewritten.
 */
class HrEmployeeScoreHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_employee_score_history';

    protected $fillable = [
        'tenant_id', 'employee_id', 'overall_score', 'confidence', 'band',
        'dimensions', 'previous_score', 'trigger', 'scored_by',
    ];

    protected $casts = [
        'dimensions'     => 'array',
        'overall_score'  => 'integer',
        'previous_score' => 'integer',
        'confidence'     => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
