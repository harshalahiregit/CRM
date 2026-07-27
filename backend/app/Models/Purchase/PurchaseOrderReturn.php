<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseOrderReturnStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase Order Return — goods sent back to a Purchase Vendor, optionally
 * against a Purchase Order. Distinct from PurchaseDebitNote: it owns an OR-####
 * series and line-level discounts. Purchase-owned (purchase_vendor_id).
 */
class PurchaseOrderReturn extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_order_returns';

    protected $fillable = [
        'tenant_id', 'or_number', 'purchase_vendor_id', 'purchase_order_id', 'created_by',
        'return_date', 'reason', 'adjust_inventory',
        'currency', 'subtotal', 'discount_total', 'tax_total', 'total',
        'status', 'issued_at', 'issued_by', 'notes',
    ];

    protected $casts = [
        'return_date'      => 'date',
        'issued_at'        => 'datetime',
        'adjust_inventory' => 'boolean',
        'subtotal'         => 'decimal:2',
        'discount_total'   => 'decimal:2',
        'tax_total'        => 'decimal:2',
        'total'            => 'decimal:2',
    ];

    protected $appends = ['status_label'];

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function items()
    {
        return $this->hasMany(PurchaseOrderReturnItem::class, 'purchase_order_return_id')->orderBy('sort_order');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /**
     * Recompute money columns from the returned lines.
     * subtotal = Σ(qty × rate) · discount_total = Σ(discount)
     * total    = subtotal − discount_total + tax  ("Total after discount")
     */
    public function recalculate(): void
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax      = 0.0;

        foreach ($this->items()->get() as $line) {
            $gross    = (float) $line->qty * (float) $line->rate;
            $lineDisc = min((float) $line->discount, $gross);
            $net      = $gross - $lineDisc;

            $subtotal += $gross;
            $discount += $lineDisc;
            $tax      += $net * ((float) $line->tax / 100);
        }

        $this->forceFill([
            'subtotal'       => round($subtotal, 2),
            'discount_total' => round($discount, 2),
            'tax_total'      => round($tax, 2),
            'total'          => round($subtotal - $discount + $tax, 2),
        ])->save();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [Status::DRAFT, Status::ISSUED]);
    }
}
