<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\CalculatesDocumentTotals;

class SalesInvoice extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, CalculatesDocumentTotals;

    protected $table = 'sales_invoices';

    protected $fillable = [
        'tenant_id', 'number', 'client_id', 'project_id', 'estimate_id',
        'date', 'due_date', 'currency', 'sale_agent', 'discount_type',
        'subtotal', 'tax_total', 'discount_total', 'total', 'paid', 'balance',
        'recurring', 'recur_interval', 'recur_type', 'cycles',
        'allowed_payment_modes', 'cancel_overdue_reminders',
        'status', 'adminnote', 'clientnote', 'terms', 'tags',
        'sent_at', 'created_by',
        'invoice_type', 'gst_paid', 'gst_amount', 'after_discount_amount',
        'supply_type', 'discount_mode', 'discount_value',
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'public_link_token', 'public_link_expiry', 'msme_udyam_number',
        'eway_bill_number', 'eway_bill_date', 'payment_reminder_enabled',
        'reminder_schedule', 'feedback_email_sent',
    ];

    protected $casts = [
        'date'       => 'date',
        'due_date'   => 'date',
        'sent_at'    => 'datetime',
        'recurring'  => 'boolean',
        'cancel_overdue_reminders' => 'boolean',
        'allowed_payment_modes'    => 'array',
        'subtotal'   => 'decimal:2',
        'tax_total'  => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total'      => 'decimal:2',
        'paid'       => 'decimal:2',
        'balance'    => 'decimal:2',
        'gst_paid'   => 'boolean',
        'gst_amount' => 'decimal:2',
        'after_discount_amount'    => 'decimal:2',
        'public_link_expiry'       => 'datetime',
        'eway_bill_date'           => 'date',
        'payment_reminder_enabled' => 'boolean',
        'reminder_schedule'        => 'array',
        'feedback_email_sent'      => 'boolean',
    ];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (SalesInvoice $inv) {
            if (empty($inv->number)) {
                $year  = date('Y');
                // withTrashed(): soft-deleted rows still occupy the UNIQUE
                // index on `number`, so they must be counted or the next
                // create reuses a number and the insert fails.
                $count = static::withTrashed()
                               ->where('tenant_id', $inv->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $inv->number = 'INV-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
            $inv->balance = $inv->total ?? 0;
        });
    }

    /* ── Relationships ─────────────────────── */
    protected $appends = ['client'];

    public function lineItems()
    {
        return $this->morphMany(SalesLineItem::class, 'lineable')->orderBy('sort_order');
    }

    public function payments()
    {
        return $this->hasMany(SalesPayment::class, 'invoice_id')->latest();
    }

    public function creditApplications()
    {
        return $this->hasMany(CreditNoteApplication::class, 'invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Helpers ─────────────────────────────── */
    public function recalcTotals(): void
    {
        $t = $this->computeDocumentTotals();

        $this->subtotal              = $t['subtotal'];
        $this->tax_total             = $t['tax_total'];
        $this->discount_total        = $t['all_discounts'];
        $this->after_discount_amount = $t['after_discount'];
        $this->gst_amount            = $t['tax_total'];
        $this->total                 = $t['total'];
        $this->supply_type           = $this->computeSupplyType();
        $this->saveQuietly();
    }

    public function recalcBalance(): void
    {
        $paid = $this->payments()->sum('amount')
              + $this->creditApplications()->sum('amount');
        $this->paid    = round($paid, 2);
        $this->balance = round($this->total - $paid, 2);

        if ($this->balance <= 0) {
            $this->status = 'Paid';
        } elseif ($this->paid > 0) {
            $this->status = 'Partially Paid';
        }

        $this->saveQuietly();
    }

    public function updateOverdueStatus(): void
    {
        if (
            $this->balance > 0
            && $this->due_date && $this->due_date->isPast()
            && !in_array($this->status, ['Draft', 'Paid', 'Cancelled', 'Overdue'])
        ) {
            // Exclude 'Overdue' above so an already-overdue invoice isn't
            // re-written on every list() call (write-on-read).
            //
            // 'Draft' is excluded because a draft has never been issued to the
            // customer, so it cannot be late. Without this, merely OPENING the
            // invoice list promoted a stale draft to Overdue — and because
            // Customer Health counts every non-draft invoice, that silently
            // moved the customer's score.
            $this->status = 'Overdue';
            $this->saveQuietly();
        }
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
