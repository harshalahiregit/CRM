<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One attendee on a Purchase kickoff meeting. Links back to the Purchase-vendor
 * contact master (PurchaseContact) when the attendee is a known contact; a free
 * name/email is allowed for external parties. Purchase-owned.
 */
class PurchaseKickoffParticipant extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_kickoff_participants';

    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'purchase_contact_id', 'user_id',
        'name', 'email', 'organisation', 'role', 'attended',
    ];

    protected $casts = [
        'attended' => 'boolean',
    ];

    public function meeting()
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function contact()
    {
        return $this->belongsTo(PurchaseContact::class, 'purchase_contact_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
