<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only trail of every score transition.
 *
 * A row is written BEFORE the live score is replaced, so `previous_*` is always
 * recoverable. `actual_outcome` / `prediction_accuracy` (from the original AIR
 * migration) stay null until a hire decision lands and can be compared back.
 */
class AirPredictionHistory extends Model
{
    protected $table = 'air_prediction_history';

    protected $fillable = [
        'tenant_id', 'candidate_id', 'job_id',
        'previous_score', 'new_score',
        'previous_recommendation', 'new_recommendation',
        'confidence_level', 'trigger',
        'predicted_score', 'predicted_recommendation', 'prediction_date',
        'actual_outcome', 'actual_performance', 'prediction_accuracy',
    ];

    protected $casts = [
        'prediction_date' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }
}
