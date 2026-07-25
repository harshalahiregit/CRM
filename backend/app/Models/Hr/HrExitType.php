<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Exit Type master (Exit Phase 1). Tenant-scoped; never hard-deleted (deactivate). */
class HrExitType extends Model
{
    use Auditable;

    protected $table = 'hr_exit_types';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description',
        'notice_required', 'default_notice_days', 'clearance_required', 'fnf_required',
        'exit_interview_required', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'notice_required'         => 'boolean',
        'default_notice_days'     => 'integer',
        'clearance_required'      => 'boolean',
        'fnf_required'            => 'boolean',
        'exit_interview_required' => 'boolean',
        'is_active'               => 'boolean',
    ];
}
