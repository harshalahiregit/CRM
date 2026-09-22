<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One seat title on one committee — presiding officer, internal member, and so
 * on. A closed vocabulary the workspace defines for itself.
 *
 * Per committee on purpose. A global role list would either be a second
 * permission system beside staff_roles, or would force every committee in a
 * workspace to use the same titles. Neither is wanted, and the existing
 * staff_roles system is left entirely alone.
 *
 * can_manage_case is stored and validated here and grants NOTHING on its own.
 * When it starts to mean something it will mean administrative authority over
 * a case — opening a round, escalating, publishing a finding already reached —
 * and only for somebody who is also a member of that case.
 */
class HrPoshCommitteeRole extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_posh_committee_roles';

    protected $fillable = [
        'tenant_id', 'committee_id', 'key', 'label',
        'can_manage_case', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'can_manage_case' => 'boolean',
        'is_active'       => 'boolean',
        'sort_order'      => 'integer',
    ];

    public function committee()
    {
        return $this->belongsTo(HrPoshCommittee::class, 'committee_id');
    }

    public function members()
    {
        return $this->hasMany(HrPoshCommitteeMember::class, 'role_id');
    }
}
