<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Support\Hr\Decision\Decision;
use Illuminate\Database\Eloquent\Model;

/**
 * One question, put to a fixed set of people at the same time.
 *
 * The roster lives on the participants and is frozen when the round opens. A
 * round is never edited to change who is being asked — it is superseded, and
 * the replacement carries its own snapshot. That is what keeps a committee
 * reshuffle or a department remapping from rewriting a decision somebody is
 * halfway through.
 */
class HrDecisionRound extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_decision_rounds';

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'purpose',
        'mode', 'quorum_required', 'state', 'outcome', 'closing_note',
        'superseded_by_round_id', 'opened_at', 'closed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'quorum_required' => 'integer',
        'opened_at'       => 'datetime',
        'closed_at'       => 'datetime',
    ];

    public function participants()
    {
        return $this->hasMany(HrDecisionParticipant::class, 'round_id')->orderBy('id');
    }

    public function subject()
    {
        return $this->morphTo();
    }

    /** Still able to receive decisions — open, or waiting on a roster change. */
    public function isLive(): bool
    {
        return in_array($this->state, Decision::LIVE, true);
    }

    public function isDecided(): bool
    {
        return $this->state === Decision::STATE_DECIDED;
    }

    /** The participants whose answers settle the outcome. */
    public function requiredParticipants()
    {
        return $this->participants->where('is_required', true);
    }
}
