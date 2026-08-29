<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\ApprovalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A central TPV approval request (Sangoe TPV §12). Generic across the ~18
 * ApprovalType kinds; separate from the onboarding_approvals chain.
 */
class TpvApproval extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_approvals';

    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled'];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

    protected $fillable = [
        'tenant_id', 'reference', 'approval_type', 'subject_type', 'subject_id', 'vendor_id',
        'title', 'description', 'priority', 'status', 'route', 'route_index', 'requested_by',
        'decided_by', 'decided_at', 'decision_remarks', 'meta',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'meta' => 'array',
        'route' => 'array',
        'route_index' => 'integer',
    ];

    protected $appends = ['type_label', 'current_level'];

    /**
     * §12 — the approver level currently awaiting sign-off, or null when the route
     * is exhausted / the approval is already decided. Drives the register's "waiting
     * on" display and the multi-level stepping in TpvApprovalService::decide().
     */
    public function getCurrentLevelAttribute(): ?string
    {
        $route = $this->route ?? [];
        if ($this->status !== 'Pending' || $route === []) {
            return null;
        }

        return $route[$this->route_index] ?? null;
    }

    protected static function booted(): void
    {
        static::creating(function (TpvApproval $a) {
            if (empty($a->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $a->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $a->reference = 'APR-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getTypeLabelAttribute(): string
    {
        return ApprovalType::label($this->approval_type);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
