<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Training Type master (L&D Phase 1). Tenant-scoped; never hard-deleted (deactivate). */
class HrTrainingType extends Model
{
    use Auditable;

    protected $table = 'hr_training_types';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'mode', 'default_duration_hours',
        'certification_applicable', 'description', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'default_duration_hours'   => 'integer',
        'certification_applicable' => 'boolean',
        'is_active'                => 'boolean',
    ];
}
