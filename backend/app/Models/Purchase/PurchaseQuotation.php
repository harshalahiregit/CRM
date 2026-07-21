<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseQuotationStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single vendor's quotation against an RFQ.
 */
class PurchaseQuotation extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_quotations';

    protected $fillable = [
        'tenant_id','purchase_rfq_id','vendor_id','created_by','quotation_number',
        'currency','subtotal','tax_total','total','status','valid_until','received_at','notes',
    ];

    protected $casts = [
        'subtotal'    => 'decimal:2',
        'tax_total'   => 'decimal:2',
        'total'       => 'decimal:2',
        'valid_until' => 'date',
        'received_at' => 'datetime',
    ];

    protected $appends = ['status_label'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseQuotation $q) {
            if (empty($q->quotation_number)) {
                $year  = date('Y');
                $count = static::withTrashed()->where('tenant_id', $q->tenant_id)
                               ->whereYear('created_at', $year)->count() + 1;
                $q->quotation_number = 'QTN-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function items()
    {
        return $this->hasMany(PurchaseQuotationItem::class, 'purchase_quotation_id')->orderBy('sort_order');
    }

    public function rfq()
    {
        return $this->belongsTo(PurchaseRfq::class, 'purchase_rfq_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isEditable(): bool
    {
        return Status::isEditable($this->status);
    }

    /** Recompute money columns from line items. Mirrors PurchaseOrder::recalcTotals(). */
    public function recalcTotals(): void
    {
        $subtotal = $taxTotal = 0;
        foreach ($this->items as $item) {
            $base      = $item->qty * $item->rate;
            $subtotal += $base;
            $taxTotal += $base * ($item->tax / 100);
        }
        $this->subtotal  = round($subtotal, 2);
        $this->tax_total = round($taxTotal, 2);
        $this->total     = round($subtotal + $taxTotal, 2);
        $this->saveQuietly();
    }
}
