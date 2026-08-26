<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A worker competency record (Sangoe TPV §15) — qualification / trade cert /
 * licence / certification / skill, with validity that drives status.
 */
class TpvWorkerCompetency extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_worker_competencies';

    public const CATEGORIES = ['Qualification', 'Trade_Certificate', 'Licence', 'Certification', 'Skill'];

    protected $fillable = [
        'tenant_id', 'tpv_worker_id', 'name', 'category', 'authority', 'reference_no', 'skill_level',
        'experience_years', 'issued_date', 'valid_until', 'evidence_path', 'verified_by', 'verified_at', 'notes',
    ];

    protected $casts = [
        'issued_date'      => 'date',
        'valid_until'      => 'date',
        'verified_at'      => 'datetime',
        'experience_years' => 'decimal:1',
    ];

    protected $appends = ['status'];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    /** Valid / Expiring (≤30d) / Expired — null valid_until means non-expiring. */
    public function getStatusAttribute(): string
    {
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
