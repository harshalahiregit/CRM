<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use App\Support\Hr\Decision\Decision;
use Illuminate\Database\Eloquent\Model;

/**
 * One seat in a decision round, and what the person in it said.
 *
 * `resolved_user_ids` is the freeze. It records who could act on this seat when
 * the round opened, and the round never re-resolves it — so editing a committee
 * or a department mapping afterwards cannot hand somebody a vote in a round
 * that was already under way, nor take one away.
 *
 * A decision is terminal per seat. There is no changing your mind: that is what
 * makes the arithmetic below trustworthy, and a genuine change of circumstance
 * is a roster change, which supersedes the round.
 */
class HrDecisionParticipant extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_decision_participants';

    protected $fillable = [
        'tenant_id', 'round_id', 'slot_key', 'slot_label', 'is_required',
        'resolver_type', 'resolver_ref', 'resolved_user_ids',
        'decision', 'decided_by', 'decided_by_name', 'decided_at', 'remarks',
    ];

    protected $casts = [
        'is_required'       => 'boolean',
        'resolved_user_ids' => 'array',
        'decided_at'        => 'datetime',
    ];

    public function round()
    {
        return $this->belongsTo(HrDecisionRound::class, 'round_id');
    }

    public function hasDecided(): bool
    {
        return $this->decision !== null;
    }

    /**
     * Whether this seat still counts towards the denominator.
     *
     * A recused seat does not. Everything else does, including a seat nobody
     * has answered yet — which is why an undecided required seat keeps a
     * quorum round open rather than letting it conclude early.
     */
    public function countsTowardsQuorum(): bool
    {
        return $this->decision !== Decision::RECUSED;
    }

    /** Whether this user was on the frozen roster for this seat. */
    public function admits(?int $userId): bool
    {
        return $userId !== null
            && in_array((int) $userId, array_map('intval', $this->resolved_user_ids ?? []), true);
    }
}
