<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseMedicalFitness as Fitness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase worker's medical / fitness record. Purchase-owned. Mirrors TPV depth. */
class PurchaseWorkerMedical extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_medicals';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'exam_date', 'expiry_date', 'fitness_status', 'blood_group', 'file_path', 'remarks',
        // Depth (TPV §16 parity) — restriction detail + sign-off + certificate.
        'restrictions', 'approved_by', 'approved_at', 'examiner_name',
        'certificate_path', 'document_path',
    ];

    protected $casts = [
        'exam_date'   => 'date',
        'expiry_date' => 'date',
        'approved_at' => 'datetime',
    ];

    protected $appends = ['fitness_label', 'is_passing', 'is_expired'];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }

    /** The medical officer who signed off the verdict (§16) — distinct from created_by. */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getFitnessLabelAttribute(): string
    {
        return Fitness::label($this->fitness_status);
    }

    /** Fit OR Fit-with-restrictions — the outcomes that allow a badge. */
    public function isPassing(): bool
    {
        return Fitness::isPassing($this->fitness_status);
    }

    public function getIsPassingAttribute(): bool
    {
        return $this->isPassing();
    }

    /** The certificate has lapsed — its currency window closed. */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }

    /** Passing AND still within its currency window — the real "medical clear" gate. */
    public function isCurrentlyValid(): bool
    {
        return $this->isPassing() && ! $this->isExpired();
    }
}
