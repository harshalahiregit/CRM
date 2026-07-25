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
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
