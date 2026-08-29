<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase worker's training record. Purchase-owned. Mirrors TpvWorkerTraining depth. */
class PurchaseWorkerTraining extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_trainings';

    /** Typed catalogue (Sangoe TPV §15 parity). Kept identical to TpvWorkerTraining::TYPES. */
    public const TYPES = [
        'Site_Induction', 'HSE_Induction', 'Toolbox', 'Fire', 'Work_At_Height',
        'Electrical', 'Confined_Space', 'Lifting', 'Equipment', 'Emergency_Response',
        'Job_Specific', 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'title', 'training_type', 'provider', 'training_date', 'expiry_date',
        'valid_until', 'status', 'score', 'file_path', 'remarks',
    ];

    protected $casts = [
        'training_date' => 'date',
        'expiry_date'   => 'date',
        'valid_until'   => 'date',
    ];

    protected $appends = ['derived_status'];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }

    /**
     * The currency window that governs whether this training is still valid.
     * Prefers the TPV-parity valid_until, falling back to the legacy expiry_date
     * so pre-depth rows keep working.
     */
    public function currencyDate()
    {
        return $this->valid_until ?? $this->expiry_date;
    }

    /**
     * Derived status (parity with TpvWorkerTraining::status). A non-Completed
     * status is passed through; a Completed one is graded against its currency
     * window into Valid / Expiring / Expired.
     */
    public function getDerivedStatusAttribute(): string
    {
        if ($this->status !== 'Completed') {
            return (string) ($this->status ?? 'Pending');
        }

        $until = $this->currencyDate();
        if ($until === null) {
            return 'Valid';
        }
        if ($until->isPast()) {
            return 'Expired';
        }
        if ($until->lte(now()->addDays(30))) {
            return 'Expiring';
        }

        return 'Valid';
    }

    /** Completed AND not lapsed — what readiness counts. */
    public function isCurrentlyValid(): bool
    {
        if ($this->status !== 'Completed') {
            return false;
        }
        $until = $this->currencyDate();

        return $until === null || ! $until->isPast();
    }
}
