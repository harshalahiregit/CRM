<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseOnboardingStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Workflow state for the 6-step Purchase-vendor onboarding wizard. Vendor
 * profile and documents live on the shared Vendor master — this tracks only
 * progress. Procurement-side mirror of TpvOnboarding, deliberately separate.
 */
class PurchaseOnboarding extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_onboardings';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'created_by', 'kickoff_meeting_id', 'current_step', 'profile',
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
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function kickoffMeeting()
    {
        return $this->belongsTo(\App\Models\Shared\KickoffMeeting::class, 'kickoff_meeting_id');
    }

    /** The shared kickoff engine attaches meetings polymorphically. */
    public function kickoffMeetings()
    {
        return $this->morphMany(\App\Models\Shared\KickoffMeeting::class, 'kickoffable');
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
        $required = \App\Models\Vendor\VendorDocument::requiredFor($this->vendor->vendor_type ?? 'standard');
        $approved = $this->vendor->documents()
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
