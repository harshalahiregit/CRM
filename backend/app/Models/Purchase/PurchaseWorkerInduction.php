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
    ];

    protected $casts = ['induction_date' => 'date'];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }
}
