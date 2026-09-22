<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One person in one seat on one committee.
 *
 * A member is an ordinary User. No new identity or role concept is introduced:
 * the eligibility rules are the ones the rest of HR already applies — an
 * active account, of staff or admin type, in the same workspace. A portal,
 * client or vendor login is not eligible whatever its internal_role string
 * happens to read, which is the same guard canManageHrQueue() opens with.
 *
 * One seat per committee per person, enforced by a unique index. Two seats
 * would let somebody count twice towards a quorum, which is a quiet way of
 * lowering it.
 */
class HrPoshCommitteeMember extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_posh_committee_members';

    protected $fillable = [
        'tenant_id', 'committee_id', 'role_id', 'user_id',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function committee()
    {
        return $this->belongsTo(HrPoshCommittee::class, 'committee_id');
    }

    public function role()
    {
        return $this->belongsTo(HrPoshCommitteeRole::class, 'role_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
