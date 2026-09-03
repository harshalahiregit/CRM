<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase worker's site-induction record. Purchase-owned. */
class PurchaseWorkerInduction extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_inductions';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'induction_date', 'status', 'conducted_by', 'remarks',
        // Session depth, mirroring tpv_worker_inductions: who delivered it, when
        // it actually ran and for how long, what was covered, the outcome, and
        // the attendance proof.
        'recorded_by', 'trainer_name', 'training_date', 'valid_until',
        'duration_minutes', 'topics', 'score', 'passed',
        'photo_path', 'signature_path', 'thumbprint_path',
    ];

    protected $casts = [
        'induction_date'   => 'date',
        'training_date'    => 'date',
        'valid_until'      => 'date',
        'topics'           => 'array',
        'duration_minutes' => 'integer',
        'score'            => 'integer',
        'passed'           => 'boolean',
    ];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }
}
