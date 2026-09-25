<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a workspace's onboarding checklist.
 *
 * The template, not the task. When an onboarding starts, these are COPIED into
 * hr_employee_onboarding_tasks — so an administrator editing this list later
 * cannot reorder, rename or remove anything on a checklist somebody is halfway
 * through. That copy is the existing data model, not something added for this:
 * the tasks table has always carried its own title and category rather than a
 * reference back here, and keeping it that way is what makes the template safe
 * to edit and safe to delete from.
 */
class HrOnboardingChecklistItem extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_onboarding_checklist_items';

    protected $fillable = [
        'tenant_id', 'category', 'title', 'owner_role',
        'is_mandatory', 'sort_order', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    /** Who may action a task seeded from this item. */
    public const OWNER_ROLES = ['HR', 'Manager', 'Employee', 'System'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
