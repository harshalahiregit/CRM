<?php

namespace App\Models\Access;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A company department.
 *
 * The company-wide list, as opposed to hr_departments (the HR module's own) and
 * ticket_departments (the Helpdesk's routing queues). Those two stay where they
 * are — each serves its module — and this is the one an admin maintains.
 */
class Department extends Model
{
    use Auditable, BelongsToTenant;

    // Prefixed, like hr_departments and ticket_departments — see the migration.
    protected $table = 'access_departments';

    protected $fillable = ['tenant_id', 'name', 'code', 'description', 'head_user_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    /** How many staff sit in this department (users.department holds the name). */
    public function getUserCountAttribute(): int
    {
        return User::where('tenant_id', $this->tenant_id)->where('department', $this->name)->count();
    }
}
