<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Support\Purchase\PurchaseMomDecisionStatus as DecisionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A decision recorded in a Purchase meeting's minutes (Sangoe TPV §9). Purchase-
 * owned (purchase_mom_decisions) — independent of the shared/TPV meeting_decisions.
 * A durable record: Active until Superseded or Rescinded.
 */
class PurchaseMomDecision extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'purchase_mom_decisions';

    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'decision_ref', 'decision',
        'decided_by_participant_id', 'decided_by_names', 'impact', 'effective_date',
        'status', 'sort_order',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'sort_order'     => 'integer',
    ];

    protected $appends = ['status_label'];

    public function meeting()
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(PurchaseKickoffParticipant::class, 'decided_by_participant_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return DecisionStatus::label($this->status);
    }
}
