<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\TpvContactStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contact person belonging to a TPV vendor — the master record reused across
 * the module. At most one primary per vendor (enforced in TpvContactService).
 */
class TpvContact extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'tpv_contacts';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'user_id', 'created_by', 'updated_by',
        'first_name', 'last_name', 'designation', 'department',
        'email', 'mobile', 'alternate_mobile',
        'is_primary', 'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    protected $appends = ['full_name', 'status_label'];

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The optional login for this contact — set once it becomes an assignable employee. */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    /** Assignable to work once it is active AND owns a login. */
    public function isAssignable(): bool
    {
        return $this->isActive() && $this->user_id !== null;
    }
}
