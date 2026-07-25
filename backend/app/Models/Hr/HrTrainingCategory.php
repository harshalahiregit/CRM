<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Training Category master (L&D Phase 1). Tenant-scoped; never hard-deleted (deactivate). */
class HrTrainingCategory extends Model
{
    use Auditable;

    protected $table = 'hr_training_categories';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
