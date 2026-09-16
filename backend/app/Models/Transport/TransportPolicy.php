<?php

namespace App\Models\Transport;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One configurable control policy — DB-020.
 *
 * Rarely touched directly; reads and writes go through TransportPolicyService,
 * which owns the DEFAULTS contract and the casting. Same relationship
 * TenantSetting has with the platform's SettingsService.
 */
class TransportPolicy extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'transport_policies';

    protected $fillable = ['tenant_id', 'key', 'value', 'description', 'updated_by'];

    protected $casts = ['value' => 'array'];
}
