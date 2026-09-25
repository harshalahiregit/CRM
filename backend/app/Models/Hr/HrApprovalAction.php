<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * One decision on one rung. Append-only by convention — nothing updates or
 * deletes these, and editing a workflow never touches them.
 */
class HrApprovalAction extends Model
{
    protected $table = 'hr_approval_actions';

    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id', 'approval_request_id', 'step_order', 'step_name',
        'actor_id', 'actor_name', 'actor_role', 'action', 'comment',
        'resolved_approver',
    ];

    protected $casts = [
        'resolved_approver' => 'array',
        'step_order'        => 'integer',
    ];

    public function request()
    {
        return $this->belongsTo(HrApprovalRequest::class, 'approval_request_id');
    }
}
