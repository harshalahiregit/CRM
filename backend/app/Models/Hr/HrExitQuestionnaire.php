<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #44 — an exit questionnaire template.
 *
 * Optionally bound to an exit type, so a resignation and a termination can ask
 * different things without anyone having to remember which form to pick.
 */
class HrExitQuestionnaire extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_exit_questionnaires';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description', 'exit_type_id',
        'is_default', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function questions()
    {
        return $this->hasMany(HrExitQuestionnaireQuestion::class, 'questionnaire_id')->orderBy('sort_order');
    }

    public function exitType()
    {
        return $this->belongsTo(HrExitType::class, 'exit_type_id');
    }
}
