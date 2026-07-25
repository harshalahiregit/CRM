<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Training Program (L&D Phase 2). Composes a Training Category, Type and Provider
 * (Phase 1 masters), optionally scoped to a Department / Designation from
 * Organization Setup (reused, not duplicated). Tenant-scoped; never hard-deleted.
 */
class HrTrainingProgram extends Model
{
    use Auditable;

    protected $table = 'hr_training_programs';

    protected $fillable = [
        'tenant_id', 'category_id', 'training_type_id', 'provider_id', 'department_id', 'designation_id',
        'program_code', 'program_name', 'description', 'objectives', 'duration', 'duration_unit',
        'mode', 'capacity', 'certification_applicable', 'passing_percentage', 'validity_days',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'duration'                 => 'integer',
        'capacity'                 => 'integer',
        'passing_percentage'       => 'integer',
        'validity_days'            => 'integer',
        'certification_applicable' => 'boolean',
        'is_active'                => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(HrTrainingCategory::class, 'category_id');
    }

    public function trainingType()
    {
        return $this->belongsTo(HrTrainingType::class, 'training_type_id');
    }

    public function provider()
    {
        return $this->belongsTo(HrTrainingProvider::class, 'provider_id');
    }

    public function department()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }
}
