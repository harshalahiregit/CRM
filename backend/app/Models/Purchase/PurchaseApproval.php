<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseApprovalStage as Stage;
use App\Support\Purchase\PurchaseApprovalStatus as Status;
use Illuminate\Database\Eloquent\Model;

/**
 * One stage in a Purchase onboarding's approval chain. Purchase-owned
 * (purchase_approvals) — independent of the shared onboarding_approvals table and
 * any TPV approval model. Keyed to the shared Vendor Master by purchase_vendor_id only.
 */
class PurchaseApproval extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'purchase_approvals';

    protected $fillable = [
        'tenant_id', 'purchase_onboarding_id', 'purchase_vendor_id',
        'stage', 'sequence', 'status', 'remarks',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
    ];

    protected $casts = [
        'sequence'    => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected $appends = ['stage_label', 'status_label'];

    public function onboarding()
    {
        return $this->belongsTo(PurchaseOnboarding::class, 'purchase_onboarding_id');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function getStageLabelAttribute(): string
    {
        return Stage::label($this->stage);
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isApproved(): bool
    {
        return $this->status === Status::APPROVED;
    }
}
