<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A document attached to a Purchase worker. Purchase-owned. */
class PurchaseWorkerDocument extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_documents';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'type', 'original_name', 'file_path', 'status', 'remarks', 'expires_at',
    ];

    protected $casts = ['expires_at' => 'date'];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }
}
