<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Metadata for one login session (device/browser/IP, remember-me, activity,
 * revocation), linked to the Sanctum token that authenticates it.
 */
class UserSession extends Model
{
    use BelongsToTenant;

    protected $table = 'user_sessions';

    protected $fillable = [
        'tenant_id', 'user_id', 'token_id', 'device', 'browser', 'ip',
        'remember_me', 'last_activity_at', 'revoked_at',
    ];

    protected $casts = [
        'remember_me'      => 'boolean',
        'last_activity_at' => 'datetime',
        'revoked_at'       => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
