<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Tpv\OnboardingApproval;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\TpvOnboardingStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Workflow state for the 6-step TPV onboarding wizard. Vendor profile and
 * documents live on the shared Vendor master — this tracks only progress.
 */
class TpvOnboarding extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'tpv_onboardings';

    protected $fillable = [
        'tenant_id','vendor_id','kickoff_meeting_id','current_step','profile',
        'status','submitted_at','approved_at','approved_by','remarks','work_start_letter_path',
        'registration_number','hold_reason',
        'kickoff_pdf_path','acknowledged','acknowledged_by','acknowledged_at','acknowledged_ip','acknowledged_browser','acknowledged_device',
        'declaration_accepted_at','onboarding_complete','completed_at','completed_ip','completed_browser','completed_device',
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

    /** Multi-level approval chain (Phase 3). */
    public function approvals()
    {
        return $this->hasMany(OnboardingApproval::class, 'tpv_onboarding_id');
    }

    /**
     * The kickoff meeting for this onboarding.
     *
     * Two ways in, by design. The direct FK (kickoff_meeting_id) is the legacy
     * placeholder the onboarding table was built with. The polymorphic side
     * (kickoffMeetings) is how the shared engine actually attaches — a meeting
     * points back here via kickoffable. New code should schedule through the
     * polymorphic relation; the FK is kept nullable so nothing that already reads
     * it breaks, and is set as a convenience pointer when a meeting is linked.
     */
    public function kickoffMeeting()
    {
        return $this->belongsTo(\App\Models\Shared\KickoffMeeting::class, 'kickoff_meeting_id');
    }

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

    public function isKickoffAcknowledged(): bool
    {
        return (bool) $this->acknowledged;
    }

    /**
     * Doc types still missing or not yet approved for this vendor. Step 5 (final
     * confirmation) is blocked until this is empty.
     */
    public function outstandingDocuments(): array
    {
        $required = \App\Models\Vendor\VendorDocument::requiredFor($this->vendor->vendor_type ?? 'standard');
        $approved = $this->vendor->documents()
            ->where('status', 'Approved')
            ->pluck('type')
            ->all();

        return array_values(array_diff($required, $approved));
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeAwaitingApproval($query)
    {
        return $query->whereIn('status', [Status::SUBMITTED, Status::UNDER_REVIEW]);
    }
}
