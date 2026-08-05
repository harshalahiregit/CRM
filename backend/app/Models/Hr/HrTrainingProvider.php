<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Training Provider master (L&D Phase 1). Tenant-scoped; never hard-deleted (deactivate). */
class HrTrainingProvider extends Model
{
    use Auditable;

    protected $table = 'hr_training_providers';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'provider_type', 'contact_person', 'email', 'phone',
        'website', 'description', 'is_active', 'created_by', 'updated_by',
        // #22 — department reuses the EXISTING hr_departments master; the rest are
        // free-text lists, since no company-wide expertise or qualification master
        // exists and inventing three would be scope nobody asked for.
        'department_id', 'expertise', 'certifications', 'qualifications', 'skills',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'expertise'      => 'array',
        'certifications' => 'array',
        'qualifications' => 'array',
        'skills'         => 'array',
    ];

    /** The department this provider is aligned to — the same master employees use. */
    public function department()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }
}
