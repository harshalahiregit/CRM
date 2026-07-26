<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Job/functional role master (e.g. "Team Lead", "Individual Contributor").
 * Named HrJobRole deliberately — it is distinct from the auth `role` on User,
 * which governs login permissions, not org structure.
 */
class HrJobRole extends Model
{
    use Auditable;

    protected $table = 'hr_job_roles';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employees()
    {
        return $this->hasMany(HrEmployee::class, 'job_role_id');
    }
}
