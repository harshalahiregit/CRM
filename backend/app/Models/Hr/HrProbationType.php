<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Probation Type master (Probation Phase 1). Tenant-scoped; never hard-deleted (deactivate). */
class HrProbationType extends Model
{
    use Auditable;

    protected $table = 'hr_probation_types';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'default_duration_days',
        'confirmation_required', 'review_required', 'extension_allowed', 'max_extensions',
        'description', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'default_duration_days' => 'integer',
        'confirmation_required' => 'boolean',
        'review_required'       => 'boolean',
        'extension_allowed'     => 'boolean',
        'max_extensions'        => 'integer',
        'is_active'             => 'boolean',
    ];
}
