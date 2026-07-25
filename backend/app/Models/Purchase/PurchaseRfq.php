<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseRfqStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Request for Quotation — the sourcing step: what we want, sent to N vendors,
 * to be quoted, compared and awarded into a PO.
 */
class PurchaseRfq extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_rfqs';

    protected $fillable = [
        'tenant_id','rfq_number','title','department','purchase_request_id','created_by',
        'required_by','closes_at','currency','status','sent_at','notes',
    ];

    protected $casts = [
        'required_by' => 'date',
        'closes_at'   => 'date',
        'sent_at'     => 'datetime',
    ];

    protected $appends = ['status_label'];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (PurchaseRfq $rfq) {
            if (empty($rfq->rfq_number)) {
                $year  = date('Y');
                $count = static::withTrashed()->where('tenant_id', $rfq->tenant_id)
                               ->whereYear('created_at', $year)->count() + 1;
                $rfq->rfq_number = 'RFQ-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function items()
    {
        return $this->hasMany(PurchaseRfqItem::class, 'purchase_rfq_id')->orderBy('sort_order');
    }

    public function rfqVendors()
    {
        return $this->hasMany(PurchaseRfqVendor::class, 'purchase_rfq_id');
    }

    public function quotations()
    {
        return $this->hasMany(PurchaseQuotation::class, 'purchase_rfq_id')->latest();
    }

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
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

    public function isOpenForQuotes(): bool
    {
        return Status::isOpenForQuotes($this->status);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', Status::OPEN_FOR_QUOTES);
    }
}
