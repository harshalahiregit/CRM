<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One external-certificate sheet upload (Purchase side). */
class PurchaseMedicalBulkBatch extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_medical_bulk_batches';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'uploaded_by', 'uploaded_side',
        'file_name', 'file_path', 'total_rows', 'created_count', 'failed_count', 'errors',
    ];

    protected $casts = [
        'errors'        => 'array',
        'total_rows'    => 'integer',
        'created_count' => 'integer',
        'failed_count'  => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
