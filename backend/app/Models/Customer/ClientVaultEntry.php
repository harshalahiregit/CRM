<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-customer credential vault. The password is encrypted at rest via the
 * `encrypted` cast; it is only ever returned decrypted through the explicit
 * reveal endpoint, never in list responses (see ClientVaultController).
 *
 * Visibility mirrors the legacy CRM: entries can be open to all staff, limited
 * to administrators, or private to their creator (administrators are NOT
 * excluded from private entries — they remain the break-glass access path).
 */
class ClientVaultEntry extends Model
{
    use BelongsToTenant;

    public const VISIBILITY_ALL     = 1;   // all staff who can access this customer
    public const VISIBILITY_ADMINS  = 2;   // administrators only
    public const VISIBILITY_CREATOR = 3;   // only me (administrators not excluded)

    protected $fillable = [
        'tenant_id', 'client_id', 'kind', 'title', 'category', 'username', 'password', 'url', 'notes',
        'file_name', 'file_path', 'mime_type', 'file_size', 'expires_at',
        'visibility', 'share_in_projects', 'created_by',
    ];

    /** A vault entry is a credential, a document, or both. */
    public const KIND_CREDENTIAL = 'credential';
    public const KIND_DOCUMENT   = 'document';
    public const KINDS = [self::KIND_CREDENTIAL, self::KIND_DOCUMENT];

    /**
     * The restricted classes the vault is for.
     *
     * Suggestions, not a closed set — the column is a plain string so a class
     * nobody anticipated can still be recorded rather than refused.
     */
    public const CATEGORIES = [
        'Agreement',
        'Legal',
        'Commercial — Confidential',
        'Sensitive Customer Information',
        'Restricted',
        'Other',
    ];

    protected $casts = [
        'password'          => 'encrypted',
        'expires_at'        => 'date',
        'file_size'         => 'integer',
        'visibility'        => 'integer',
        'share_in_projects' => 'boolean',
    ];

    protected $hidden = ['password'];

    /** Can this user see the entry at all? */
    public function isVisibleTo(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;   // admins see every entry, including private ones
        }

        return match ((int) $this->visibility) {
            self::VISIBILITY_ADMINS  => false,
            self::VISIBILITY_CREATOR => $this->created_by === $user->id,
            default                  => true,
        };
    }

    /** Only the creator or an administrator may edit/delete an entry. */
    public function isManageableBy(User $user): bool
    {
        return $user->role === 'admin' || $this->created_by === $user->id;
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('visibility', self::VISIBILITY_ALL)
              ->orWhere(fn (Builder $w) => $w->where('visibility', self::VISIBILITY_CREATOR)
                  ->where('created_by', $user->id));
        });
    }
}
