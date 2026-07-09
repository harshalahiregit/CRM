<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class SalesInvoice extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

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
                $count = static::where('tenant_id', $inv->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $inv->number = 'INV-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
            $inv->balance = $inv->total ?? 0;
        });
    }

    /* ── Relationships ─────────────────────── */
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
        $subtotal = $taxTotal = $discountTotal = 0;
        foreach ($this->lineItems as $li) {
            $base          = $li->qty * $li->rate;
            $afterDis      = $base - $li->discount;
            $taxAmount     = $afterDis * ($li->tax / 100);
            $subtotal     += $base;
            $discountTotal+= $li->discount;
            $taxTotal     += $taxAmount;
        }
        $this->subtotal              = round($subtotal, 2);
        $this->tax_total             = round($taxTotal, 2);
        $this->discount_total        = round($discountTotal, 2);
        $this->after_discount_amount = round($subtotal - $discountTotal, 2);
        $this->gst_amount            = round($taxTotal, 2);
        $this->total                 = round($subtotal - $discountTotal + $taxTotal, 2);
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
            && $this->due_date->isPast()
            && !in_array($this->status, ['Paid', 'Cancelled'])
        ) {
            $this->status = 'Overdue';
            $this->saveQuietly();
        }
    }
}
