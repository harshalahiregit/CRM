<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseRequestStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequest extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_requests';

    protected $fillable = [
        'tenant_id','pr_number','title','department','vendor_id','requested_by',
        'required_by','priority','justification','currency',
        'subtotal','tax_total','total',
        'status','submitted_at','approved_at','approved_by','remarks',
    ];

    protected $casts = [
        'required_by'  => 'date',
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'subtotal'     => 'decimal:2',
        'tax_total'    => 'decimal:2',
        'total'        => 'decimal:2',
    ];

    protected $appends = ['status_label'];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (PurchaseRequest $pr) {
            if (empty($pr->pr_number)) {
                $year  = date('Y');
                $count = static::withTrashed()
                               ->where('tenant_id', $pr->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $pr->pr_number = 'PR-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function items()
    {
        return $this->hasMany(PurchaseRequestItem::class, 'purchase_request_id')->orderBy('sort_order');
    }

    /** Suggested vendor — the unified vendor master, shared with TPV. */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
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

    /** Recompute money columns from line items. Mirrors SalesInvoice::recalcTotals(). */
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

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeAwaitingApproval($query)
    {
        return $query->where('status', Status::SUBMITTED);
    }
}
