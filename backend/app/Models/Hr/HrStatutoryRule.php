<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable statutory rule. The `config` payload carries every rate, ceiling
 * and slab — no statutory number lives in code.
 */
class HrStatutoryRule extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_statutory_rules';

    // #30 — 'wcp' (Workmen's Compensation) and 'mediclaim' join the original six.
    // Both are premiums rather than contributions, but they are configured,
    // resolved and effective-dated exactly like the rest, so they belong here
    // rather than in a parallel scheme of their own.
    public const TYPES = ['pf', 'esic', 'pt', 'bonus', 'gratuity', 'tds', 'wcp', 'mediclaim'];

    protected $fillable = [
        'tenant_id', 'rule_type', 'state', 'effective_from', 'effective_to',
        'config', 'is_active', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'config'         => 'array',
        'is_active'      => 'boolean',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];
}
