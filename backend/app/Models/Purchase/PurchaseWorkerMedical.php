<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase worker's medical / fitness record. Purchase-owned. */
class PurchaseWorkerMedical extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_medicals';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'exam_date', 'expiry_date', 'fitness_status', 'blood_group', 'file_path', 'remarks',
    ];

    protected $casts = ['exam_date' => 'date', 'expiry_date' => 'date'];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }
}
