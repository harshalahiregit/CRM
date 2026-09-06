<?php

namespace App\Models\Access;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff JOB role — what lands in `users.internal_role`.
 *
 * Not an account type. See the migration for why the two are different things
 * and why only this one is editable.
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
