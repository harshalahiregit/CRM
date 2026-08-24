<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * §15 — the Vault's audit trail.
 *
 * The document's distinction between Files and Vault rests on Vault having
 * "stronger RBAC and audit trails". The RBAC was already there in
 * ClientVaultEntry::visibility; this is the other half.
 *
 * Append-only by construction: there is no update path and no `updated_at`. An
 * audit trail somebody can edit is not one.
 */
class ClientVaultAccessLog extends Model
{
    use BelongsToTenant;

    protected $table = 'client_vault_access_log';

    public const UPDATED_AT = null;

    public const REVEALED = 'revealed';
    public const COPIED   = 'copied';
    public const CREATED  = 'created';
    public const UPDATED  = 'updated';
    public const DELETED  = 'deleted';
    /** A document leaving the vault is a disclosure, exactly like revealing a password. */
    public const DOWNLOADED = 'downloaded';

    protected $fillable = [
        'tenant_id', 'client_id', 'vault_entry_id', 'user_id', 'action', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function entry()
    {
        return $this->belongsTo(ClientVaultEntry::class, 'vault_entry_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
