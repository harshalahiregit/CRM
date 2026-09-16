<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * One external-certificate sheet upload (TPV side).
 *
 * A bulk import that silently drops rows is worse than one that refuses the
 * file, so every batch records what went in, what landed, and the reason for
 * each rejected row — the vendor fixes those rows and re-uploads only them.
 */
class TpvMedicalBulkBatch extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_medical_bulk_batches';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'uploaded_by', 'uploaded_side',
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
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
