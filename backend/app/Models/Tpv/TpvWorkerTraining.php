<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A typed worker training record (Sangoe TPV §15). Distinct from the single HSSE
 * induction — this is the full course catalogue with validity.
 */
class TpvWorkerTraining extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_worker_trainings';

    public const TYPES = [
        'Site_Induction', 'HSE_Induction', 'Toolbox', 'Fire', 'Work_At_Height',
        'Electrical', 'Confined_Space', 'Lifting', 'Equipment', 'Emergency_Response',
        // §15 — an explicit job-specific training type (was folded into 'Other').
        'Job_Specific', 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'tpv_worker_id', 'training_type', 'provider', 'completed_date',
        'valid_until', 'passed', 'score', 'certificate_path', 'notes',
    ];

    protected $casts = [
        'completed_date' => 'date',
        'valid_until' => 'date',
        'passed' => 'boolean',
        'score' => 'integer',
    ];

    protected $appends = ['status'];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function getStatusAttribute(): string
    {
        if (! $this->passed) {
            return 'Failed';
        }
        if ($this->valid_until === null) {
            return 'Valid';
        }
        if ($this->valid_until->isPast()) {
            return 'Expired';
        }
        if ($this->valid_until->lte(now()->addDays(30))) {
            return 'Expiring';
        }

        return 'Valid';
    }
}
