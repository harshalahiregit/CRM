<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A structured agenda item on a Purchase meeting (Meeting.docx §3). Carries the
 * per-item discussion/decision that the structured MOM (§7) renders. Purchase-
 * owned (purchase_mom_agenda_items); independent of the shared/TPV table.
 */
class PurchaseMomAgendaItem extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'purchase_mom_agenda_items';

    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'item', 'description',
        'owner_participant_id', 'owner_names', 'duration_minutes', 'priority',
        'discussion', 'decision', 'sort_order',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'sort_order'       => 'integer',
    ];

    public function meeting()
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function owner()
    {
        return $this->belongsTo(PurchaseKickoffParticipant::class, 'owner_participant_id');
    }
}
