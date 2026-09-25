<?php

namespace App\Models\Hr;

use App\Support\Hr\Approval\ApprovalState;
use Illuminate\Database\Eloquent\Model;

/**
 * One approval in progress against one business record.
 *
 * The snapshot on this row is authoritative for the whole of its life. Reading
 * the workflow again mid-flight is the bug this table exists to prevent.
 */
class HrApprovalRequest extends Model
{
    protected $table = 'hr_approval_requests';

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'process', 'employee_id',
        'workflow_id', 'workflow_version', 'steps_snapshot', 'current_step',
        'state', 'amount', 'blocked_reason', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'steps_snapshot' => 'array',
        'current_step'   => 'integer',
        'amount'         => 'decimal:2',
        'opened_at'      => 'datetime',
        'closed_at'      => 'datetime',
    ];

    public function actions()
    {
        return $this->hasMany(HrApprovalAction::class, 'approval_request_id')
            ->orderBy('step_order');
    }

    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function isOpen(): bool
    {
        return ApprovalState::isOpen($this->state);
    }

    /** The snapshot rung the request is sitting on, or null when finished. */
    public function currentStepDefinition(): ?array
    {
        foreach ($this->steps_snapshot ?: [] as $step) {
            if ((int) ($step['step_order'] ?? 0) === (int) $this->current_step) {
                return $step;
            }
        }

        return null;
    }

    /** Ordered rungs after the current one — used to decide if this is the last. */
    public function remainingSteps(): array
    {
        return array_values(array_filter(
            $this->steps_snapshot ?: [],
            fn ($s) => (int) ($s['step_order'] ?? 0) > (int) $this->current_step
        ));
    }

    public function isFinalStep(): bool
    {
        return $this->remainingSteps() === [];
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
