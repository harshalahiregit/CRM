<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use Illuminate\Database\Eloquent\Model;

class TpvWorkerMedical extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_worker_medicals';

    protected $fillable = [
        'tenant_id','tpv_worker_id','recorded_by','exam_type','exam_date','valid_until','examiner_name','clinic_name',
        'height_cm','weight_kg','bp_systolic','bp_diastolic','vision',
        'screening_responses','screening_score','screening_band',
        'fitness_status','restrictions','signature_path',
    ];

    protected $casts = [
        'exam_date'           => 'date',
        'valid_until'         => 'date',
        'height_cm'           => 'decimal:1',
        'weight_kg'           => 'decimal:1',
        'screening_responses' => 'array',
        'screening_score'     => 'integer',
    ];

    protected $appends = ['fitness_label', 'bmi', 'is_expired'];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getFitnessLabelAttribute(): string
    {
        return Fitness::label($this->fitness_status);
    }

    /** Derived from the recorded measurements — never stored. */
    public function getBmiAttribute(): ?float
    {
        if (! $this->height_cm || ! $this->weight_kg) {
            return null;
        }
        $m = ((float) $this->height_cm) / 100;

        return $m > 0 ? round(((float) $this->weight_kg) / ($m * $m), 1) : null;
    }

    public function isPassing(): bool
    {
        return Fitness::isPassing($this->fitness_status);
    }

    /** The certificate has lapsed — its currency window closed. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** Serialized flag so the UI can badge a lapsed medical without recomputing. */
    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }

    /** Fit AND still within its currency window — the real "medical clear" gate. */
    public function isCurrentlyValid(): bool
    {
        return $this->isPassing() && ! $this->isExpired();
    }
}
