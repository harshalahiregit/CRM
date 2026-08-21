<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §4 — a single touch with a customer: a call, an email, a visit.
 *
 * Deliberately separate from Meetings. A meeting has an agenda, attendance and
 * minutes and runs through the shared meeting engine; an activity is the
 * lightweight record of everything else, and it is what makes the question
 * "what happened with this customer in the last 30 days?" answerable.
 */
class ClientActivity extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** §4's list, plus Escalation so a raised complaint shows on the timeline. */
    public const TYPES = ['Call', 'Email', 'WhatsApp', 'Visit', 'Meeting', 'Follow-up', 'Note', 'Escalation'];

    public const DIRECTIONS = ['Inbound', 'Outbound'];

    public const OUTCOMES = [
        'Connected', 'No answer', 'Left message', 'Rescheduled',
        'Information shared', 'Resolved', 'Needs follow-up',
    ];

    protected $fillable = [
        'tenant_id', 'client_id', 'client_contact_id', 'type', 'direction', 'subject',
        'summary', 'outcome', 'occurred_at', 'duration_minutes', 'follow_up_on',
        'follow_up_done', 'created_by',
    ];

    protected $casts = [
        'occurred_at'      => 'datetime',
        'follow_up_on'     => 'date',
        'follow_up_done'   => 'boolean',
        'duration_minutes' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function contact()
    {
        return $this->belongsTo(ClientContact::class, 'client_contact_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A follow-up that is due and nobody has ticked off. */
    public function scopeFollowUpDue($query)
    {
        return $query->whereNotNull('follow_up_on')
            ->where('follow_up_done', false)
            ->whereDate('follow_up_on', '<=', now()->toDateString());
    }
}
