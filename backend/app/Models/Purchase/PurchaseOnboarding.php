<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseOnboardingStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Workflow state for the 6-step Purchase-vendor onboarding wizard. Belongs to a
 * PurchaseVendor (purchase_vendor_id); profile lives on this record's JSON and
 * documents in purchase_documents. Fully Purchase-owned and independent.
 */
class PurchaseOnboarding extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_onboardings';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'created_by', 'kickoff_meeting_id', 'current_step', 'profile',
        'status', 'submitted_at', 'approved_at', 'approved_by', 'remarks', 'work_start_letter_path',
        'registration_number', 'hold_reason',
        'kickoff_pdf_path', 'acknowledged', 'acknowledged_by', 'acknowledged_at', 'acknowledged_ip', 'acknowledged_browser', 'acknowledged_device',
        'declaration_accepted_at', 'onboarding_complete', 'completed_at', 'completed_ip', 'completed_browser', 'completed_device',
    ];

    protected $casts = [
        'current_step'            => 'integer',
        'profile'                 => 'array',
        'submitted_at'            => 'datetime',
        'approved_at'             => 'datetime',
        'acknowledged'            => 'boolean',
        'acknowledged_at'         => 'datetime',
        'declaration_accepted_at' => 'datetime',
        'onboarding_complete'     => 'boolean',
        'completed_at'            => 'datetime',
    ];

    protected $appends = ['status_label'];

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function kickoffMeeting()
    {
        return $this->belongsTo(\App\Models\Purchase\PurchaseKickoffMeeting::class, 'kickoff_meeting_id');
    }

    /** Purchase kickoff meetings for this onboarding (Purchase-owned engine). */
    public function kickoffMeetings()
    {
        return $this->hasMany(\App\Models\Purchase\PurchaseKickoffMeeting::class, 'purchase_onboarding_id');
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

    /** Doc types still missing or not yet approved for this vendor. */
    public function outstandingDocuments(): array
    {
        $required = \App\Models\Purchase\PurchaseDocument::requiredFor($this->vendor->vendor_type ?? 'standard');
        $approved = \App\Models\Purchase\PurchaseDocument::forTenant($this->tenant_id)
            ->where('purchase_vendor_id', $this->purchase_vendor_id)
            ->where('status', 'Approved')
            ->pluck('type')
            ->all();

        return array_values(array_diff($required, $approved));
    }

    public function scopeAwaitingApproval($query)
    {
        return $query->whereIn('status', [Status::SUBMITTED, Status::UNDER_REVIEW]);
    }
}
