<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own WhatsApp sender. Mirrors TenantMailSetting deliberately --
 * same resolution rule, same "empty means keep" convention on the secret.
 */
class TenantWhatsAppSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_whatsapp_settings';

    protected $fillable = [
        'tenant_id', 'provider', 'access_token', 'phone_number_id', 'waba_id',
        'api_version', 'display_phone_number', 'verified_name', 'enabled', 'verified_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'enabled'      => 'boolean',
        'verified_at'  => 'datetime',
    ];

    // The token never leaves the server. The screen gets has_token instead.
    protected $hidden = ['access_token'];

    protected $appends = ['has_token'];

    public function getHasTokenAttribute(): bool
    {
        return ! empty($this->attributes['access_token']);
    }

    /** Usable = switched on with the minimum needed to address the Cloud API. */
    public function isUsable(): bool
    {
        return $this->enabled
            && ! empty($this->attributes['access_token'])
            && ! empty($this->phone_number_id);
    }
}
