<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase Non-Conformance Report — the Purchase-side mirror of TpvNcr (parity
 * rule). Raised → Assigned → Response → Corrective Action → Verification → Closed.
 */
class PurchaseNcr extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_ncrs';

    public const SEVERITIES = ['Minor', 'Major', 'Critical'];

    public const STATUSES = ['Raised', 'Assigned', 'Response', 'Corrective_Action', 'Verification', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'purchase_vendor_id', 'source_type', 'source_id', 'title',
        'requirement', 'finding', 'severity', 'status', 'responsible_by', 'due_date', 'response',
        'corrective_action', 'evidence_path', 'raised_by', 'verified_by', 'verified_at', 'closed_at', 'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $appends = ['is_overdue'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseNcr $ncr) {
            if (empty($ncr->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $ncr->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $ncr->reference = 'PNCR-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && $this->status !== 'Closed';
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_by');
    }

    public function source()
    {
        return $this->morphTo();
    }
}
