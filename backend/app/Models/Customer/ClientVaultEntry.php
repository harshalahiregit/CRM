<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-customer credential vault. The password is encrypted at rest via the
 * `encrypted` cast; it is only ever returned decrypted through the explicit
 * reveal endpoint, never in list responses (see ClientVaultController).
 */
class ClientVaultEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'title', 'username', 'password', 'url', 'notes', 'created_by',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    protected $hidden = ['password'];
}
