<?php

namespace App\Models\Hr;

use App\Models\StaffRole;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One department that has to sign off an exit, and who may sign for it.
 *
 * The name is the link to a clearance item, and deliberately by VALUE rather
 * than by key: hr_exit_clearance_items.department holds its own copy, so
 * renaming or retiring a department here cannot rewrite or orphan an item on a
 * clearance somebody is already working through. The same snapshot-by-copy the
 * onboarding checklist uses, and the reason deleting a department needs no
 * guard against historical records.
 *
 * Authorities are a UNION of two configurations: named users, and staff roles
 * whose members all qualify. Roles exist so an organisation does not have to
 * maintain a list of individuals every time somebody joins or leaves IT.
 */
class HrClearanceDepartment extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_clearance_departments';

    protected $fillable = [
        'tenant_id', 'name', 'is_mandatory', 'sort_order', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function users()
    {
        return $this->belongsToMany(
            User::class, 'hr_clearance_department_users', 'department_id', 'user_id'
        )->withTimestamps();
    }

    public function roles()
    {
        return $this->belongsToMany(
            StaffRole::class, 'hr_clearance_department_roles', 'department_id', 'staff_role_id'
        )->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether anybody has been named for this department at all.
     *
     * The question that decides fallback, and it asks about CONFIGURATION, not
     * about who the configuration currently resolves to. A department whose
     * named users have all been deactivated is still configured — it has a
     * broken configuration, which is a different thing from having none, and
     * quietly handing it back to the HR queue would hide that.
     */
    public function isConfigured(): bool
    {
        return $this->users()->exists() || $this->roles()->exists();
    }
}
