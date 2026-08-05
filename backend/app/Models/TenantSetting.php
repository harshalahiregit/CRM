<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single tenant-scoped setting row in the generic key/value store. Reads/writes
 * go through App\Services\Settings\SettingsService (which handles casting,
 * encryption and caching) — models are rarely touched directly.
 */
class TenantSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_settings';

    protected $fillable = [
        'tenant_id', 'group', 'key', 'value', 'is_encrypted', 'autoload',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
        'autoload'     => 'boolean',
    ];
}
