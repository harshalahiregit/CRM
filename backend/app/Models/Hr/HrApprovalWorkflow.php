<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A configured approval ladder for one process in one tenant.
 *
 * Auditable, because changing who approves leave is itself a decision somebody
 * should be able to account for later.
 */
class HrApprovalWorkflow extends Model
{
    use Auditable;

    protected $table = 'hr_approval_workflows';

    protected $fillable = [
        'tenant_id', 'process', 'name', 'is_active', 'version',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'version'   => 'integer',
    ];

    public function steps()
    {
        return $this->hasMany(HrApprovalWorkflowStep::class, 'workflow_id')
            ->orderBy('step_order');
    }

    /** Only the rungs that count — an inactive step is skipped, not deleted. */
    public function activeSteps()
    {
        return $this->steps()->where('is_active', true);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
