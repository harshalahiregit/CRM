<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One tenant configuration value (blueprint §9's non-list settings tabs:
 * inventory rules, approvals, min/max defaults, sale-price rule). Stored as
 * JSON so a setting can be a scalar, list or object without a migration.
 */
class Config extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_config';

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected $casts = ['value' => 'array'];
}
