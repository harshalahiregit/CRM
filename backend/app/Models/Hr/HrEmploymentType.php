<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * What kind of employment somebody is on — Permanent, Contract, Intern, or
 * whatever this company calls it.
 *
 * Company-defined and nothing is seeded. Shipping a starter list would make a
 * guess look like a decision, and the point of the master is that the guess
 * belongs to the workspace.
 *
 * Distinct from three neighbours it is easy to mistake for:
 * hr_hiring_requests.employment_type is a requisition field published to
 * external job boards and stays a fixed vocabulary; hr_employees.worker_type
 * is the org chart's three-value grouping; hr_employees.category is the salary
 * register's minimum-wage skill category.
 */
class HrEmploymentType extends Model
{
    use Auditable;

    protected $table = 'hr_employment_types';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description', 'sort_order', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function employees()
    {
        return $this->hasMany(HrEmployee::class, 'employment_type_id');
    }
}
