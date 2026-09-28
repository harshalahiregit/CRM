<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Support\Hr\StaffPermission;
use Illuminate\Database\Eloquent\Model;

/**
 * A named set of permissions, following the old CRM's tblroles.
 *
 * The slug is load-bearing twice over: it is what gets written to
 * users.internal_role, and therefore what every hardcoded check in the codebase
 * matches on — canManageHrQueue, AgencyContext, AdvanceTierService. Renaming a
 * role is free; changing its slug is not, which is why the slug is set once on
 * creation and never derived from the name again.
 */
class StaffRole extends Model
{
    use BelongsToTenant;

    protected $table = 'staff_roles';

    protected $fillable = [
        // `permissions` says what the role may DO; `scope` says whose records it
        // may do it to. Two halves of one answer, kept apart on purpose — see
        // App\Support\Hr\DataScope. Defaults to 'global', which is the behaviour
        // every role had before the column existed.
        'tenant_id', 'name', 'slug', 'description', 'permissions', 'scope', 'is_system',
        // A row that exists to declare a legal internal_role NAME and nothing
        // else — `hr` and `manager`, which routes/sangoetrack.php gates on and
        // which had previously been written down only in the unused
        // access_roles table. Never a permission bypass: such a role grants
        // exactly what its empty permission set grants.
        'is_vocabulary_only',
    ];

    protected $casts = [
        'permissions'        => 'array',
        'is_system'          => 'boolean',
        'is_vocabulary_only' => 'boolean',
    ];

    protected $appends = ['granted_count'];

    public function users()
    {
        return $this->hasMany(User::class, 'staff_role_id');
    }

    /** Always sanitised on the way out: an unknown module in the blob grants nothing. */
    public function grants(): array
    {
        return StaffPermission::sanitise($this->permissions ?? []);
    }

    /** How much this role actually gives, for a list that has to be scannable. */
    public function getGrantedCountAttribute(): int
    {
        return array_sum(array_map('count', $this->grants()));
    }

    /**
     * A slug from a name, for custom roles.
     *
     * Kept to the same shape as the seeded slugs — lowercase, underscores —
     * because it lands in internal_role beside them.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));

        return substr($slug, 0, 60) ?: 'role';
    }
}
