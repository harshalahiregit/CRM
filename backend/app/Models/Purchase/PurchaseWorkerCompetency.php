<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase worker competency record (mirror of TPV §15) — qualification /
 * trade cert / licence / certification / skill, with validity that drives
 * status. Purchase-owned (purchase_worker_competencies), independent of TPV.
 */
class PurchaseWorkerCompetency extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_competencies';

    public const CATEGORIES = ['Qualification', 'Trade_Certificate', 'Licence', 'Certification', 'Skill'];

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'name', 'category', 'authority', 'reference_no', 'skill_level',
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
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
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
