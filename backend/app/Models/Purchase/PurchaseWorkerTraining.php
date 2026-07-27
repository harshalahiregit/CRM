<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase worker's training record. Purchase-owned. */
class PurchaseWorkerTraining extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_trainings';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'title', 'training_date', 'expiry_date', 'status', 'score', 'file_path', 'remarks',
    ];

    protected $casts = ['training_date' => 'date', 'expiry_date' => 'date'];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }
}
