<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\CalculatesDocumentTotals;
use App\Models\Traits\LogsSalesActivity;

class Proposal extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, CalculatesDocumentTotals, LogsSalesActivity;

    protected $fillable = [
        'tenant_id', 'subject', 'reference_no', 'rel_type', 'rel_id', 'contact_id', 'project_id',
        'date', 'open_till', 'currency', 'discount_type', 'discount_amount',
        'subtotal', 'tax_total', 'total', 'status', 'assigned_to',
        'proposal_to', 'address', 'city', 'state', 'country', 'zip',
        'email', 'phone', 'allow_comments', 'tags', 'notes', 'terms', 'portal_token',
        'sent_at', 'accepted_at', 'declined_at', 'created_by',
        'template_id', 'qr_code_data', 'public_view_otp_enabled',
        'email_opened_at', 'email_opened_device', 'email_opened_count',
        'pdf_header', 'pdf_footer', 'company_logo_url', 'company_stamp_url',
        'portal_viewed_at', 'portal_view_count', 'acceptance_ip', 'acceptance_user_agent',
        'email_subject', 'email_body', 'email_cc', 'last_emailed_at',
        'converted_estimate_id', 'converted_invoice_id',
        'supply_type', 'discount_mode', 'discount_value',
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
        'portal_viewed_at'        => 'datetime',
        'portal_view_count'       => 'integer',
        'email_cc'                => 'array',
        'last_emailed_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Proposal $proposal) {
            if (empty($proposal->portal_token)) {
                $proposal->portal_token = Str::random(40);
            }
            if (empty($proposal->reference_no) && $proposal->tenant_id) {
                $year  = now()->format('Y');
                $count = static::withTrashed()
                    ->where('tenant_id', $proposal->tenant_id)
                    ->whereYear('created_at', $year)
                    ->count() + 1;
                $proposal->reference_no = 'PROP-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
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

    public function contact()
    {
        return $this->belongsTo(\App\Models\Customer\ClientContact::class, 'contact_id');
    }

    public function pages()
    {
        return $this->morphMany(ContentPage::class, 'pageable')->orderBy('sort_order');
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
        $t = $this->computeDocumentTotals();

        $this->subtotal        = $t['subtotal'];
        $this->tax_total       = $t['tax_total'];
        $this->discount_amount = $t['all_discounts'];
        $this->total           = $t['total'];
        $this->supply_type     = $this->computeSupplyType();
        $this->saveQuietly();
    }
}
