<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\GoodsReceiptStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'tenant_id','grn_number','purchase_order_id','purchase_vendor_id','received_by',
        'received_date','delivery_note_ref','status','notes','confirmed_at',
    ];

    protected $casts = [
        'received_date' => 'date',
        'confirmed_at'  => 'datetime',
    ];

    protected $appends = ['status_label'];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (GoodsReceipt $grn) {
            if (empty($grn->grn_number)) {
                $year  = date('Y');
                $count = static::withTrashed()
                               ->where('tenant_id', $grn->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $grn->grn_number = 'GRN-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isEditable(): bool
    {
        return Status::isEditable($this->status);
    }
}
