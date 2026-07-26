<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HelpdeskSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'public_form_enabled', 'public_form_logo_variant', 'default_department_id',
        'ticket_manager_ids',
        // Auto-assignment (REQ-06). Strategy defaults to 'none' at the DB level,
        // so a tenant that never opts in keeps today's manual-assignment flow.
        'auto_assign_strategy', 'default_assignee_id', 'last_auto_assigned_user_id',
    ];

    /** Supported auto-assignment strategies. See TicketAssignmentService::autoAssign(). */
    public const AUTO_ASSIGN_STRATEGIES = ['none', 'round_robin', 'least_busy', 'department_manager'];

    protected $casts = [
        'public_form_enabled'        => 'boolean',
        'ticket_manager_ids'         => 'array',
        'default_assignee_id'        => 'integer',
        'last_auto_assigned_user_id' => 'integer',
    ];

    public function defaultDepartment()
    {
        return $this->belongsTo(TicketDepartment::class, 'default_department_id');
    }

    public function defaultAssignee()
    {
        return $this->belongsTo(\App\Models\User::class, 'default_assignee_id');
    }
}
