<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Leave Type master (Leave Phase 1). Tenant-scoped; never hard-deleted (deactivate). */
class HrLeaveType extends Model
{
    use Auditable;

    protected $table = 'hr_leave_types';

    public const CATEGORIES = ['Casual', 'Sick', 'Earned', 'Maternity', 'Paternity', 'Restricted', 'Unpaid'];

    protected $fillable = [
        'tenant_id', 'name', 'code', 'category', 'paid', 'yearly_limit',
        'carry_forward', 'max_carry_forward', 'requires_attachment', 'requires_approval',
        'color', 'description', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'paid'                => 'boolean',
        'yearly_limit'        => 'decimal:1',
        'carry_forward'       => 'boolean',
        'max_carry_forward'   => 'decimal:1',
        'requires_attachment' => 'boolean',
        'requires_approval'   => 'boolean',
        'is_active'           => 'boolean',
    ];
}
