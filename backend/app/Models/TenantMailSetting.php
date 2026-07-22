<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantMailSetting extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'tenant_mail_settings';

    protected $fillable = [
        'tenant_id', 'host', 'port', 'username', 'password',
        'encryption', 'from_name', 'from_email', 'reply_to', 'enabled',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'enabled'  => 'boolean',
        'port'     => 'integer',
    ];

    // Never expose the password; the API returns has_password instead and an
    // empty password on update means "keep the existing one".
    protected $hidden = ['password'];

    protected $appends = ['has_password'];

    public function getHasPasswordAttribute(): bool
    {
        return ! empty($this->attributes['password']);
    }

    /**
     * Usable = enabled with the minimum fields required to build a transport.
     */
    public function isUsable(): bool
    {
        return $this->enabled && ! empty($this->host) && ! empty($this->from_email);
    }
}
