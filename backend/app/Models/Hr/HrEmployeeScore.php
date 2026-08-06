<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #39/#40 — an employee's current overall score and insights.
 *
 * One row per employee. Every recalculation overwrites this row and APPENDS to
 * HrEmployeeScoreHistory, so "what is it now" is one lookup and "how did it get
 * here" is never lost.
 */
class HrEmployeeScore extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_employee_scores';

    protected $fillable = [
        'tenant_id', 'employee_id', 'overall_score', 'provisional_score', 'confidence',
        'band', 'summary', 'dimensions', 'applied_weights',
        'positives', 'improvements', 'risks', 'insight_narrative',
        'insight_source', 'insight_meta', 'insights_generated_at',
        'scored_at', 'scored_by',
    ];

    protected $casts = [
        'dimensions'            => 'array',
        'applied_weights'       => 'array',
        'positives'             => 'array',
        'improvements'          => 'array',
        'risks'                 => 'array',
        'insight_meta'          => 'array',
        'insights_generated_at' => 'datetime',
        'scored_at'             => 'datetime',
        'overall_score'         => 'integer',
        'provisional_score'     => 'integer',
        'confidence'            => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
