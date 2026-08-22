<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseMomActionStatus as ActionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A single action item raised in a Purchase meeting's minutes (Sangoe TPV §9).
 * Purchase-owned (purchase_mom_action_items) — independent of the shared/TPV
 * kickoff_mom_items. Carries an owner (Rule 11) and, at closure, evidence or a
 * verification note (Rule 12).
 */
class PurchaseMomActionItem extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'purchase_mom_action_items';

    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'action_ref', 'description',
        'responsible_participant_id', 'responsible_names', 'responsible_org',
        'status', 'priority', 'target_date', 'remark', 'notes',
        'evidence_path', 'verification_note', 'closed_at', 'verified_at', 'verified_by',
        'sort_order',
    ];

    protected $casts = [
        'target_date' => 'date',
        'closed_at'   => 'datetime',
        'verified_at' => 'datetime',
        'sort_order'  => 'integer',
    ];

    /** Evidence is fetched through a guarded endpoint, never exposed as a raw path. */
    protected $hidden = ['evidence_path'];

    protected $appends = ['status_label', 'is_open', 'is_overdue', 'has_evidence'];

    public function meeting()
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function responsible()
    {
        return $this->belongsTo(PurchaseKickoffParticipant::class, 'responsible_participant_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return ActionStatus::label($this->status);
    }

    public function getIsOpenAttribute(): bool
    {
        return ActionStatus::isOpen($this->status);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->is_open && $this->target_date && $this->target_date->isPast();
    }

    public function getHasEvidenceAttribute(): bool
    {
        return ! empty($this->evidence_path);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ActionStatus::OPEN_STATES);
    }
}
