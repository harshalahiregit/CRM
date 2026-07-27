<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One configured Purchase setting (tenant_id, key, value). Presence of a row
 * means the tenant has explicitly configured that key — reads fall back to
 * PurchaseSettingService::DEFAULTS when no row exists.
 */
class PurchaseSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_settings';

    protected $fillable = ['tenant_id', 'key', 'value', 'updated_by'];
}
