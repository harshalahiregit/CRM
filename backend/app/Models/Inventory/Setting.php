<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Inventory Settings master data.
 *
 * Six lookup tables share one shape (tenant_id, name, order), so rather than six
 * near-identical model files they share this base. Each concrete class only
 * declares its table; SettingsService drives them all generically.
 */
abstract class Setting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'order'];

    protected $casts = ['order' => 'integer'];
}
