<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseOrderStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'tenant_id','po_number','purchase_request_id','purchase_quotation_id','purchase_contract_id','purchase_vendor_id','created_by',
        'title','department','order_date','expected_delivery_date','currency',
        'subtotal','tax_total','total',
        'status','issued_at','issued_by','terms','notes',
    ];

    protected $casts = [
        'order_date'             => 'date',
        'expected_delivery_date' => 'date',
        'issued_at'              => 'datetime',
        'subtotal'               => 'decimal:2',
        'tax_total'              => 'decimal:2',
        'total'                  => 'decimal:2',
    ];

    protected $appends = ['status_label', 'received_percent'];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $po) {
            if (empty($po->po_number)) {
                // Uses the Settings prefix/next-number once configured; otherwise
                // keeps this module's original PO-YYYY-NNN format untouched.
                $po->po_number = \App\Support\Purchase\PurchaseNumbering::next(
                    (int) $po->tenant_id,
                    'pur_order_prefix',
                    'next_po_number',
                    function () use ($po) {
                        $year  = date('Y');
                        $count = static::withTrashed()
                                       ->where('tenant_id', $po->tenant_id)
                                       ->whereYear('created_at', $year)
                                       ->count() + 1;

                        return 'PO-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
                    },
                );
            }
        });
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id')->orderBy('sort_order');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    /** The approved Purchase Request this PO was converted from, if any. */
    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    /** The winning quotation this PO was awarded from, if any. */
    public function quotation()
    {
        return $this->belongsTo(PurchaseQuotation::class, 'purchase_quotation_id');
    }

    /** The rate contract this PO draws pre-negotiated pricing from, if any. */
    public function contract()
    {
        return $this->belongsTo(PurchaseContract::class, 'purchase_contract_id');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id')->latest();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
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

    public function isReceivable(): bool
    {
        return Status::isReceivable($this->status);
    }

    /** Weighted-by-quantity receipt progress (0–100), for the UI progress bar. */
    public function getReceivedPercentAttribute(): int
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $ordered = $items->sum('qty');
        if ($ordered <= 0) {
            return 0;
        }

        return (int) round(min(100, ($items->sum('received_qty') / $ordered) * 100));
    }

    /** Recompute money columns from line items. Mirrors PurchaseRequest::recalcTotals(). */
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

    /**
     * Recompute status from item received quantities. Called after a Goods
     * Receipt is confirmed. Never downgrades a terminal/manual state (Closed,
     * Cancelled, Draft).
     */
    public function syncReceiptStatus(): void
    {
        if (in_array($this->status, [Status::DRAFT, Status::CLOSED, Status::CANCELLED], true)) {
            return;
        }

        $items = $this->items()->get();
        $fullyReceived = $items->every(fn ($i) => $i->received_qty >= $i->qty);
        $anyReceived   = $items->contains(fn ($i) => $i->received_qty > 0);

        $this->status = $fullyReceived ? Status::RECEIVED
            : ($anyReceived ? Status::PARTIALLY_RECEIVED : Status::ISSUED);
        $this->saveQuietly();
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeReceivable($query)
    {
        return $query->whereIn('status', Status::RECEIVABLE);
    }
}
