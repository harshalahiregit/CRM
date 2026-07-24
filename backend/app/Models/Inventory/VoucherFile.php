<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Paperwork that arrives with the goods — delivery challan, test certificate,
 * inspection photo, proof of delivery. Held against the document rather than
 * the product, because "show me the paperwork for this delivery" is the
 * question an auditor actually asks.
 *
 * Files live on the private disk; there is no public URL. Downloads go through
 * an authenticated, tenant-scoped endpoint.
 */
class VoucherFile extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_voucher_files';

    /** What the attachment is FOR — drives which screen offers it. */
    public const KINDS = ['document', 'inspection', 'pod'];

    protected $fillable = [
        'tenant_id', 'voucher_id', 'file_path', 'file_name',
        'file_size', 'mime_type', 'kind', 'uploaded_by',
    ];

    protected $casts = ['file_size' => 'integer'];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
