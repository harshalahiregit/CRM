<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A branch — the top of the workplace hierarchy and, deliberately, the place a
 * statutory jurisdiction lives. `work_state` is normalised through the SAME
 * WorkStates vocabulary Professional Tax is keyed by, so a later phase can resolve
 * PT from an employee's branch without a second list of states.
 */
class HrBranch extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_branches';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'address', 'city', 'work_state', 'pincode',
        'phone', 'email', 'is_head_office', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_head_office' => 'boolean',
        'is_active'      => 'boolean',
    ];

    /** Canonical on write, exactly as on HrEmployee — a city stores as null. */
    public function setWorkStateAttribute($value): void
    {
        $this->attributes['work_state'] = \App\Support\Hr\WorkStates::normalize($value);
    }

    public function offices()
    {
        return $this->hasMany(HrOffice::class, 'branch_id');
    }
}
