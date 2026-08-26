<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-tenant override for one TPV configuration group (Sangoe TPV §34).
 *
 * The `payload` is a partial JSON document shaped like the group's config
 * baseline; the {@see \App\Support\Tpv\TpvSettings} catalog deep-merges it over
 * the shipped defaults. No row for a group == the shipped defaults are used.
 */
class TpvSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_settings';

    /** The configurable groups (§34). Kept here so routes/validation share one list. */
    public const GROUPS = [
        'strike_rules',
        'vpi',
        'approval_workflow',
        'authority_matrix',
        'approval_types',
        'gate',
        'violation_ladder',
        'onboarding_checklists',
        'approval_routing',
        'catalogs',
    ];

    protected $fillable = ['tenant_id', 'group', 'payload', 'updated_by'];

    protected $casts = [
        'payload' => 'array',
    ];
}
