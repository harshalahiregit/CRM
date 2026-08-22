<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Support\Purchase\PurchaseMomIssueStatus as IssueStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * An issue raised in a Purchase meeting's minutes (Sangoe TPV §9). Purchase-owned
 * (purchase_mom_issues) — independent of the shared/TPV meeting_issues. Trackable
 * to resolution and convertible to an NCR or a CAPA (converted_to / _ref / _id).
 */
class PurchaseMomIssue extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'purchase_mom_issues';

    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'issue_ref', 'title', 'description',
        'category', 'severity', 'owner_participant_id', 'owner_names', 'due_date', 'status',
        'converted_to', 'converted_ref', 'converted_id', 'sort_order',
    ];

    protected $casts = [
        'due_date'   => 'date',
        'sort_order' => 'integer',
    ];

    protected $appends = ['status_label', 'is_open', 'is_overdue', 'is_converted'];

    public function meeting()
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function owner()
    {
        return $this->belongsTo(PurchaseKickoffParticipant::class, 'owner_participant_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return IssueStatus::label($this->status);
    }

    public function getIsOpenAttribute(): bool
    {
        return IssueStatus::isOpen($this->status);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->is_open && $this->due_date && $this->due_date->isPast();
    }

    public function getIsConvertedAttribute(): bool
    {
        return ! empty($this->converted_to);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', IssueStatus::OPEN_STATES);
    }
}
