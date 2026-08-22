<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseApprovalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An entry in the Purchase central approval register (Sangoe TPV §12). Purchase-
 * owned (purchase_approval_requests) — independent of the shared/TPV tpv_approvals
 * and of the Purchase onboarding stage chain (purchase_approvals / PurchaseApproval).
 * A generic, additive record: raised Pending, then Approved / Rejected / Cancelled
 * by an admin. No routing or side-effects (mirrors TpvApproval).
 */
class PurchaseApprovalRequest extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_approval_requests';

    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled'];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

    protected $fillable = [
        'tenant_id', 'reference', 'approval_type', 'subject_type', 'subject_id',
        'purchase_vendor_id', 'title', 'description', 'priority', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_remarks', 'meta',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'meta'       => 'array',
    ];

    protected $appends = ['type_label'];

    protected static function booted(): void
    {
        static::creating(function (self $approval) {
            if (empty($approval->reference)) {
                $year = now()->year;
                $n = static::withTrashed()
                    ->where('tenant_id', $approval->tenant_id)
                    ->whereYear('created_at', $year)
                    ->count() + 1;
                $approval->reference = sprintf('PAPR-%d-%03d', $year, $n);
            }
        });
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function getTypeLabelAttribute(): string
    {
        return PurchaseApprovalType::label($this->approval_type);
    }
}
