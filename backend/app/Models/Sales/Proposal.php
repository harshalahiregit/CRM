<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use App\Models\Traits\BelongsToTenant;

class Proposal extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'subject', 'rel_type', 'rel_id', 'project_id',
        'date', 'open_till', 'currency', 'discount_type', 'discount_amount',
        'subtotal', 'tax_total', 'total', 'status', 'assigned_to',
        'proposal_to', 'address', 'city', 'state', 'country', 'zip',
        'email', 'phone', 'allow_comments', 'tags', 'notes', 'portal_token',
        'sent_at', 'accepted_at', 'declined_at', 'created_by',
        'template_id', 'qr_code_data', 'public_view_otp_enabled',
        'email_opened_at', 'email_opened_device', 'email_opened_count',
        'pdf_header', 'pdf_footer', 'company_logo_url', 'company_stamp_url',
    ];

    protected $casts = [
        'date'         => 'date',
        'open_till'    => 'date',
        'sent_at'      => 'datetime',
        'accepted_at'  => 'datetime',
        'declined_at'  => 'datetime',
        'allow_comments'=> 'boolean',
        'subtotal'     => 'decimal:2',
        'tax_total'    => 'decimal:2',
        'total'        => 'decimal:2',
        'public_view_otp_enabled' => 'boolean',
        'email_opened_at'         => 'datetime',
        'email_opened_count'      => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Proposal $proposal) {
            if (empty($proposal->portal_token)) {
                $proposal->portal_token = Str::random(40);
            }
        });
    }

    /* ── Relationships ─────────────────────── */
    public function lineItems()
    {
        return $this->morphMany(SalesLineItem::class, 'lineable')->orderBy('sort_order');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template()
    {
        return $this->belongsTo(ProposalTemplate::class, 'template_id');
    }

    /* ── Scopes ─────────────────────────────── */
    public function scopeOfStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /* ── Helpers ─────────────────────────────── */
    public function recalcTotals(): void
    {
        $subtotal      = 0;
        $taxTotal      = 0;
        $discountTotal = 0;

        foreach ($this->lineItems as $li) {
            $base      = $li->qty * $li->rate;
            $discount  = $li->discount;
            $afterDis  = $base - $discount;
            $taxAmount = $afterDis * ($li->tax / 100);

            $subtotal      += $base;
            $discountTotal += $discount;
            $taxTotal      += $taxAmount;
        }

        $this->subtotal        = round($subtotal, 2);
        $this->tax_total       = round($taxTotal, 2);
        $this->discount_amount = round($discountTotal, 2);
        $this->total           = round($subtotal - $discountTotal + $taxTotal, 2);
        $this->saveQuietly();
    }
}
