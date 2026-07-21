<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseDebitNoteStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseDebitNote extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_debit_notes';

    protected $fillable = [
        'tenant_id','debit_number','purchase_order_id','vendor_id','created_by',
        'debit_date','reason','adjust_inventory','currency',
        'subtotal','tax_total','total','amount_refunded','amount_applied','balance',
        'status','issued_at','issued_by','notes',
    ];

    protected $casts = [
        'debit_date'       => 'date',
        'issued_at'        => 'datetime',
        'adjust_inventory' => 'boolean',
        'subtotal'         => 'decimal:2',
        'tax_total'        => 'decimal:2',
        'total'            => 'decimal:2',
        'amount_refunded'  => 'decimal:2',
        'amount_applied'   => 'decimal:2',
        'balance'          => 'decimal:2',
    ];

    protected $appends = ['status_label'];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (PurchaseDebitNote $dn) {
            if (empty($dn->debit_number)) {
                $year  = date('Y');
                $count = static::withTrashed()
                               ->where('tenant_id', $dn->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $dn->debit_number = 'PDN-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function items()
    {
        return $this->hasMany(PurchaseDebitNoteItem::class, 'purchase_debit_note_id')->orderBy('sort_order');
    }

    public function refunds()
    {
        return $this->hasMany(PurchaseDebitRefund::class, 'purchase_debit_note_id')->latest('refund_date');
    }

    /** Credits netted from this note against invoices. */
    public function creditApplications()
    {
        return $this->hasMany(PurchaseCreditApplication::class, 'purchase_debit_note_id')->latest('applied_date');
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

    public function isRefundable(): bool
    {
        return Status::isRefundable($this->status);
    }

    /** An Open note with balance left can be netted against an invoice. */
    public function isApplicable(): bool
    {
        return $this->status === Status::OPEN && (float) $this->balance > 0;
    }

    /** Recompute money columns from returned line items. */
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
        $this->balance   = round($this->total - $this->amount_refunded - $this->amount_applied, 2);
        $this->saveQuietly();
    }

    /**
     * Recompute the claim's settlement (cash refunds + credits netted against
     * invoices) → balance → status. Called after a refund OR a credit application
     * changes. Only meaningful once Open.
     *
     * A note fully consumed by netting alone settles just as one fully refunded
     * in cash — both drive Open → Settled.
     */
    public function recalcRefunds(): void
    {
        if (! in_array($this->status, [Status::OPEN, Status::SETTLED], true)) {
            return;
        }

        $refunded = round((float) $this->refunds()->sum('amount'), 2);
        $applied  = round((float) $this->creditApplications()->sum('amount'), 2);

        $this->amount_refunded = $refunded;
        $this->amount_applied  = $applied;
        $this->balance         = round((float) $this->total - $refunded - $applied, 2);
        $this->status          = ($this->balance <= 0 && $this->total > 0) ? Status::SETTLED : Status::OPEN;
        $this->saveQuietly();
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeOpen($query)
    {
        return $query->where('status', Status::OPEN);
    }
}
