<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditNote extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'credit_notes';

    protected $fillable = [
        'tenant_id', 'number', 'client_id', 'invoice_id',
        'date', 'currency', 'subtotal', 'tax_total', 'total', 'remaining',
        'reason', 'adminnote', 'clientnote', 'terms', 'status', 'created_by',
    ];

    protected $casts = [
        'date'      => 'date',
        'subtotal'  => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total'     => 'decimal:2',
        'remaining' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (CreditNote $cn) {
            if (empty($cn->number)) {
                // withTrashed(): soft-deleted rows still occupy the UNIQUE
                // index on `number`, so they must be counted or the next
                // create reuses a number and the insert fails.
                $count = static::withTrashed()->where('tenant_id', $cn->tenant_id)->count() + 1;
                $cn->number = 'CN-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
            // Coalesce to 0 so a credit note without line items still inserts
            // (remaining is NOT NULL and total may be unset at creating time).
            $cn->total ??= 0;
            $cn->remaining = $cn->total;
        });
    }

    protected $appends = ['client'];

    public function lineItems()
    {
        return $this->morphMany(SalesLineItem::class, 'lineable')->orderBy('sort_order');
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function applications()
    {
        return $this->hasMany(CreditNoteApplication::class, 'credit_note_id');
    }

    public function refunds()
    {
        return $this->hasMany(CreditNoteRefund::class, 'credit_note_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recalcRemaining(): void
    {
        $applied  = $this->applications()->sum('amount');
        $refunded = $this->refunds()->sum('amount');
        $this->remaining = round($this->total - $applied - $refunded, 2);

        if ($this->remaining <= 0) {
            $this->status = 'Fully Applied';
        } elseif ($applied > 0 || $refunded > 0) {
            $this->status = 'Partially Applied';
        }

        $this->saveQuietly();
    }

    /**
     * The customer this document belongs to.
     *
     * Named `customer` because `client` is taken by the accessor below: the API has
     * always exposed `client` as a company-name STRING (the lists and detail pages
     * render it directly), and a relation of that name would serialise an object
     * there instead — breaking every one of those call sites.
     */
    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer\Client::class, 'client_id');
    }

    /**
     * Company name for display.
     *
     * Appended because ~10 UI call sites read `.client` and nothing ever provided
     * it — the Client column on these lists rendered blank. Eager-load
     * `customer:id,company` when listing, or this lazy-loads per row.
     */
    public function getClientAttribute(): ?string
    {
        return $this->customer?->company;
    }
}
