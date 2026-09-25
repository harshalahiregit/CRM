<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's POSH committee.
 *
 * Configuration only at this stage: no case references a committee yet, and
 * nothing here grants access to anything.
 *
 * ACTIVE IS THE LINE. An inactive committee may be edited into any state at
 * all, including an unusable one — that is how a workspace builds one up. An
 * ACTIVE committee must always satisfy its invariants, so an edit that would
 * break one is refused rather than leaving a committee that is switched on and
 * cannot function. The way out of a refusal is always stated: deactivate the
 * committee, or appoint somebody.
 */
class HrPoshCommittee extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_posh_committees';

    public const QUORUM_ALL = 'all_members';
    public const QUORUM_N_OF_M = 'n_of_m';
    public const QUORUM_MODES = [self::QUORUM_ALL, self::QUORUM_N_OF_M];

    protected $fillable = [
        'tenant_id', 'name', 'quorum_mode', 'quorum_required',
        'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'quorum_required' => 'integer',
        'sort_order'      => 'integer',
    ];

    public function roles()
    {
        return $this->hasMany(HrPoshCommitteeRole::class, 'committee_id')->orderBy('sort_order')->orderBy('id');
    }

    public function members()
    {
        return $this->hasMany(HrPoshCommitteeMember::class, 'committee_id')->orderBy('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Seats that currently count — towards a quorum, and towards anything else. */
    public function activeMembers()
    {
        return $this->members->where('is_active', true);
    }

    public function activeRoles()
    {
        return $this->roles->where('is_active', true);
    }

    /**
     * Whether anybody on this committee could open or close an inquiry.
     *
     * Asked of the ACTIVE roles held by ACTIVE members: a role that can manage
     * cases but which nobody holds is the same as not having one.
     */
    public function hasCaseManager(): bool
    {
        $managerRoleIds = $this->activeRoles()->where('can_manage_case', true)->pluck('id')->all();

        if ($managerRoleIds === []) {
            return false;
        }

        return $this->activeMembers()->contains(
            fn (HrPoshCommitteeMember $m) => in_array((int) $m->role_id, array_map('intval', $managerRoleIds), true)
        );
    }

    /** How many approvals this committee needs, given who is on it today. */
    public function effectiveQuorum(): int
    {
        return $this->quorum_mode === self::QUORUM_N_OF_M
            ? (int) $this->quorum_required
            : $this->activeMembers()->count();
    }
}
