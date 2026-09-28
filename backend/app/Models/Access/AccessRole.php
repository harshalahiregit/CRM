<?php

namespace App\Models\Access;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * LEGACY — retired. Nothing in the application reads or writes this any more.
 *
 * This was a second staff-role catalogue beside `staff_roles`. Both wrote
 * `users.internal_role`; only `staff_roles` carried permissions, so this one
 * could never be the authority — it was a role manager that granted nothing,
 * with a routed Settings screen and no rows in it.
 *
 * `staff_roles` owns the vocabulary now, together with the permissions and the
 * scope. The two slugs that existed ONLY here — `hr` and `manager`, which
 * routes/sangoetrack.php gates on via `role:admin,hr,manager` — are
 * vocabulary-only entries in StaffRoleTemplate, so that gate is unaffected.
 *
 * The class and its table are kept on purpose rather than deleted: this
 * environment cannot see production, and if rows exist there somebody needs a
 * way to read them before anything is dropped. Removing the create/update/
 * delete PATH is what stops a second source of authority; dropping the data is
 * a separate, later decision.
 *
 * DO NOT wire this back up. New role work belongs in App\Models\StaffRole.
 */
class AccessRole extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'access_roles';

    protected $fillable = ['tenant_id', 'name', 'slug', 'description', 'is_active', 'is_system'];

    protected $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];

    /** How many staff currently hold this role. */
    public function getUserCountAttribute(): int
    {
        return \App\Models\User::where('tenant_id', $this->tenant_id)
            ->where('internal_role', $this->slug)->count();
    }
}
