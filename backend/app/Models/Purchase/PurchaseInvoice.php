<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseInvoiceStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_invoices';

    protected $fillable = [
        'tenant_id','invoice_number','purchase_order_id','vendor_id','created_by',
        'title','vendor_invoice_ref','invoice_date','due_date','currency',
        'subtotal','tax_total','total','amount_paid','amount_credited','balance',
        'status','match_verdict','approved_at','approved_by','terms','notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'approved_at'  => 'datetime',
        'subtotal'     => 'decimal:2',
        'tax_total'    => 'decimal:2',
        'total'        => 'decimal:2',
        'amount_paid'    => 'decimal:2',
        'amount_credited' => 'decimal:2',
        'balance'        => 'decimal:2',
    ];

    protected $appends = ['status_label', 'is_overdue'];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (PurchaseInvoice $inv) {
            if (empty($inv->invoice_number)) {
                $year  = date('Y');
                $count = static::withTrashed()
                               ->where('tenant_id', $inv->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $inv->invoice_number = 'PINV-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'purchase_invoice_id')->orderBy('sort_order');
    }

    public function payments()
    {
        return $this->hasMany(PurchaseInvoicePayment::class, 'purchase_invoice_id')->latest('payment_date');
    }

    /** Debit-note credits netted against this invoice. */
    public function creditApplications()
    {
        return $this->hasMany(PurchaseCreditApplication::class, 'purchase_invoice_id')->latest('applied_date');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function purchaseOrder()
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

    public function isPayable(): bool
    {
        return Status::isPayable($this->status);
    }

    /** Past the due date with an outstanding balance and not cancelled/paid. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date
            && $this->balance > 0
            && $this->due_date->isPast()
            && ! in_array($this->status, [Status::PAID, Status::CANCELLED], true);
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
        $this->balance   = round($this->total - $this->amount_paid - $this->amount_credited, 2);
        $this->saveQuietly();
    }

    /**
     * Recompute settlement (cash payments + netted credits) → balance → status.
     * Called after a payment OR a credit application changes. Never touches Draft
     * or Cancelled.
     *
     * A debit-note credit settles the invoice exactly like a cash payment, so it
     * counts toward Paid / Partially_Paid the same way — an invoice fully cleared
     * by credit alone is Paid, not left Awaiting.
     */
    public function recalcPayments(): void
    {
        if (in_array($this->status, [Status::DRAFT, Status::CANCELLED], true)) {
            return;
        }

        $paid     = round((float) $this->payments()->sum('amount'), 2);
        $credited = round((float) $this->creditApplications()->sum('amount'), 2);
        $settled  = round($paid + $credited, 2);

        $this->amount_paid     = $paid;
        $this->amount_credited = $credited;
        $this->balance         = round((float) $this->total - $settled, 2);

        $this->status = $this->balance <= 0 && $this->total > 0
            ? Status::PAID
            : ($settled > 0 ? Status::PARTIALLY_PAID : Status::AWAITING_PAYMENT);

        $this->saveQuietly();
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopePayable($query)
    {
        return $query->whereIn('status', Status::PAYABLE);
    }
}
