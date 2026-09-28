<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** One rung of a configured ladder. */
class HrApprovalWorkflowStep extends Model
{
    protected $table = 'hr_approval_workflow_steps';

    protected $fillable = [
        'tenant_id', 'workflow_id', 'step_order', 'name',
        'approver_type', 'approver_ref', 'levels_up', 'conditions', 'is_active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'is_active'  => 'boolean',
        'step_order' => 'integer',
        'levels_up'  => 'integer',
    ];

    public function workflow()
    {
        return $this->belongsTo(HrApprovalWorkflow::class, 'workflow_id');
    }

    /**
     * The shape copied into a request's snapshot.
     *
     * Deliberately a plain array rather than the model: once a request starts,
     * it must not be able to read anything that can still change underneath it.
     */
    public function toSnapshot(): array
    {
        return [
            'step_order'    => (int) $this->step_order,
            'name'          => (string) $this->name,
            'approver_type' => (string) $this->approver_type,
            'approver_ref'  => $this->approver_ref !== null ? (int) $this->approver_ref : null,
            'levels_up'     => (int) ($this->levels_up ?: 1),
            'conditions'    => $this->conditions ?: [],
        ];
    }
}
